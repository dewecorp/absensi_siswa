mysqldump: [Warning] Using a password on the command line interface can be insecure.
-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
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
) ENGINE=InnoDB AUTO_INCREMENT=466 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_absensi`
--

LOCK TABLES `tb_absensi` WRITE;
/*!40000 ALTER TABLE `tb_absensi` DISABLE KEYS */;
INSERT INTO `tb_absensi` VALUES (56,22,'2026-01-16',NULL,NULL,'Hadir',NULL),(57,23,'2026-01-16',NULL,NULL,'Hadir',NULL),(58,24,'2026-01-16',NULL,NULL,'Hadir',NULL),(59,25,'2026-01-16',NULL,NULL,'Hadir',NULL),(60,26,'2026-01-16',NULL,NULL,'Hadir',NULL),(61,27,'2026-01-16',NULL,NULL,'Hadir',NULL),(62,28,'2026-01-16',NULL,NULL,'Hadir',NULL),(63,29,'2026-01-16',NULL,NULL,'Hadir',NULL),(64,30,'2026-01-16',NULL,NULL,'Sakit',NULL),(65,31,'2026-01-16',NULL,NULL,'Hadir',NULL),(66,32,'2026-01-16',NULL,NULL,'Hadir',NULL),(67,33,'2026-01-16',NULL,NULL,'Hadir',NULL),(68,34,'2026-01-16',NULL,NULL,'Hadir',NULL),(69,35,'2026-01-16',NULL,NULL,'Hadir',NULL),(70,36,'2026-01-16',NULL,NULL,'Sakit',NULL),(71,37,'2026-01-16',NULL,NULL,'Hadir',NULL),(72,38,'2026-01-16',NULL,NULL,'Hadir',NULL),(73,39,'2026-01-16',NULL,NULL,'Alpa',NULL),(74,40,'2026-01-16',NULL,NULL,'Hadir',NULL),(75,41,'2026-01-16',NULL,NULL,'Hadir',NULL),(76,42,'2026-01-16',NULL,NULL,'Hadir',NULL),(77,43,'2026-01-16',NULL,NULL,'Hadir',NULL),(78,44,'2026-01-16',NULL,NULL,'Hadir',NULL),(79,45,'2026-01-16',NULL,NULL,'Hadir',NULL),(80,46,'2026-01-16',NULL,NULL,'Hadir',NULL),(81,47,'2026-01-16',NULL,NULL,'Hadir',NULL),(82,48,'2026-01-16',NULL,NULL,'Hadir',NULL),(83,49,'2026-01-16',NULL,NULL,'Hadir',NULL),(84,50,'2026-01-16',NULL,NULL,'Hadir',NULL),(85,51,'2026-01-16',NULL,NULL,'Hadir',NULL),(86,52,'2026-01-16',NULL,NULL,'Hadir',NULL),(87,53,'2026-01-16',NULL,NULL,'Hadir',NULL),(88,54,'2026-01-16',NULL,NULL,'Hadir',NULL),(89,55,'2026-01-16',NULL,NULL,'Hadir',NULL),(90,56,'2026-01-16',NULL,NULL,'Hadir',NULL),(91,57,'2026-01-16',NULL,NULL,'Hadir',NULL),(92,58,'2026-01-16',NULL,NULL,'Hadir',NULL),(93,59,'2026-01-16',NULL,NULL,'Hadir',NULL),(94,60,'2026-01-16',NULL,NULL,'Hadir',NULL),(95,61,'2026-01-16',NULL,NULL,'Hadir',NULL),(96,62,'2026-01-16',NULL,NULL,'Hadir',NULL),(97,63,'2026-01-16',NULL,NULL,'Hadir',NULL),(98,64,'2026-01-16',NULL,NULL,'Hadir',NULL),(99,65,'2026-01-16',NULL,NULL,'Sakit',NULL),(100,66,'2026-01-16',NULL,NULL,'Hadir',NULL),(101,67,'2026-01-16',NULL,NULL,'Hadir',NULL),(102,68,'2026-01-16',NULL,NULL,'Hadir',NULL),(103,69,'2026-01-16',NULL,NULL,'Hadir',NULL),(104,70,'2026-01-16',NULL,NULL,'Hadir',NULL),(105,71,'2026-01-16',NULL,NULL,'Hadir',NULL),(106,72,'2026-01-16',NULL,NULL,'Hadir',NULL),(107,73,'2026-01-16',NULL,NULL,'Hadir',NULL),(108,74,'2026-01-16',NULL,NULL,'Hadir',NULL),(109,75,'2026-01-16',NULL,NULL,'Hadir',NULL),(110,76,'2026-01-16',NULL,NULL,'Hadir',NULL),(111,77,'2026-01-16',NULL,NULL,'Hadir',NULL),(112,78,'2026-01-16',NULL,NULL,'Hadir',NULL),(113,79,'2026-01-16',NULL,NULL,'Hadir',NULL),(114,80,'2026-01-16',NULL,NULL,'Hadir',NULL),(115,81,'2026-01-16',NULL,NULL,'Hadir',NULL),(116,82,'2026-01-16',NULL,NULL,'Hadir',NULL),(117,83,'2026-01-16',NULL,NULL,'Hadir',NULL),(118,84,'2026-01-16',NULL,NULL,'Hadir',NULL),(119,85,'2026-01-16',NULL,NULL,'Hadir',NULL),(120,86,'2026-01-16',NULL,NULL,'Hadir',NULL),(121,87,'2026-01-16',NULL,NULL,'Hadir',NULL),(122,88,'2026-01-16',NULL,NULL,'Hadir',NULL),(123,89,'2026-01-16',NULL,NULL,'Hadir',NULL),(124,90,'2026-01-16',NULL,NULL,'Hadir',NULL),(125,91,'2026-01-16',NULL,NULL,'Hadir',NULL),(126,92,'2026-01-16',NULL,NULL,'Hadir',NULL),(127,93,'2026-01-16',NULL,NULL,'Hadir',NULL),(128,94,'2026-01-16',NULL,NULL,'Hadir',NULL),(129,95,'2026-01-16',NULL,NULL,'Hadir',NULL),(130,96,'2026-01-16',NULL,NULL,'Alpa',NULL),(131,97,'2026-01-16',NULL,NULL,'Hadir',NULL),(132,98,'2026-01-16',NULL,NULL,'Hadir',NULL),(133,99,'2026-01-16',NULL,NULL,'Hadir',NULL),(134,100,'2026-01-16',NULL,NULL,'Hadir',NULL),(135,101,'2026-01-16',NULL,NULL,'Hadir',NULL),(136,102,'2026-01-16',NULL,NULL,'Hadir',NULL),(137,103,'2026-01-16',NULL,NULL,'Alpa',NULL),(138,104,'2026-01-16',NULL,NULL,'Hadir',NULL),(139,105,'2026-01-16',NULL,NULL,'Izin',NULL),(140,106,'2026-01-16',NULL,NULL,'Hadir',NULL),(141,107,'2026-01-16',NULL,NULL,'Hadir',NULL),(142,108,'2026-01-16',NULL,NULL,'Hadir',NULL),(143,109,'2026-01-16',NULL,NULL,'Hadir',NULL),(144,110,'2026-01-16',NULL,NULL,'Hadir',NULL),(145,111,'2026-01-16',NULL,NULL,'Hadir',NULL),(146,112,'2026-01-16',NULL,NULL,'Hadir',NULL),(147,113,'2026-01-16',NULL,NULL,'Alpa',NULL),(148,114,'2026-01-16',NULL,NULL,'Hadir',NULL),(149,115,'2026-01-16',NULL,NULL,'Hadir',NULL),(150,116,'2026-01-16',NULL,NULL,'Hadir',NULL),(151,117,'2026-01-16',NULL,NULL,'Hadir',NULL),(152,118,'2026-01-16',NULL,NULL,'Hadir',NULL),(153,119,'2026-01-16',NULL,NULL,'Hadir',NULL),(154,120,'2026-01-16',NULL,NULL,'Hadir',NULL),(155,121,'2026-01-16',NULL,NULL,'Hadir',NULL),(156,122,'2026-01-16',NULL,NULL,'Hadir',NULL),(157,123,'2026-01-16',NULL,NULL,'Hadir',NULL),(158,124,'2026-01-16',NULL,NULL,'Hadir',NULL),(159,125,'2026-01-16',NULL,NULL,'Hadir',NULL),(160,126,'2026-01-16',NULL,NULL,'Hadir',NULL),(161,127,'2026-01-16',NULL,NULL,'Hadir',NULL),(162,128,'2026-01-16',NULL,NULL,'Alpa',NULL),(163,129,'2026-01-16',NULL,NULL,'Hadir',NULL),(164,130,'2026-01-16',NULL,NULL,'Hadir',NULL),(165,131,'2026-01-16',NULL,NULL,'Hadir',NULL),(166,132,'2026-01-16',NULL,NULL,'Hadir',NULL),(167,133,'2026-01-16',NULL,NULL,'Hadir',NULL),(168,125,'2026-01-22',NULL,NULL,'Hadir',NULL),(169,126,'2026-01-22',NULL,NULL,'Hadir',NULL),(170,127,'2026-01-22',NULL,NULL,'Hadir',NULL),(171,128,'2026-01-22',NULL,NULL,'Hadir',NULL),(172,129,'2026-01-22',NULL,NULL,'Hadir',NULL),(173,130,'2026-01-22',NULL,NULL,'Hadir',NULL),(174,131,'2026-01-22',NULL,NULL,'Hadir',NULL),(175,132,'2026-01-22',NULL,NULL,'Hadir',NULL),(176,133,'2026-01-22',NULL,NULL,'Izin',NULL),(177,115,'2026-01-22',NULL,NULL,'Hadir',NULL),(178,116,'2026-01-22',NULL,NULL,'Hadir',NULL),(179,117,'2026-01-22',NULL,NULL,'Hadir',NULL),(180,118,'2026-01-22',NULL,NULL,'Hadir',NULL),(181,119,'2026-01-22',NULL,NULL,'Hadir',NULL),(182,120,'2026-01-22',NULL,NULL,'Sakit',NULL),(183,121,'2026-01-22',NULL,NULL,'Hadir',NULL),(184,122,'2026-01-22',NULL,NULL,'Hadir',NULL),(185,123,'2026-01-22',NULL,NULL,'Hadir',NULL),(186,124,'2026-01-22',NULL,NULL,'Hadir',NULL),(187,22,'2026-01-22',NULL,NULL,'Hadir',24),(188,23,'2026-01-22',NULL,NULL,'Hadir',24),(189,24,'2026-01-22',NULL,NULL,'Hadir',24),(190,25,'2026-01-22',NULL,NULL,'Hadir',24),(191,26,'2026-01-22',NULL,NULL,'Hadir',24),(192,27,'2026-01-22',NULL,NULL,'Hadir',24),(193,28,'2026-01-22',NULL,NULL,'Hadir',24),(194,30,'2026-01-22',NULL,NULL,'Hadir',24),(195,29,'2026-01-22',NULL,NULL,'Hadir',24),(196,31,'2026-01-22',NULL,NULL,'Hadir',24),(197,32,'2026-01-22',NULL,NULL,'Hadir',24),(198,33,'2026-01-22',NULL,NULL,'Hadir',24),(199,34,'2026-01-22',NULL,NULL,'Hadir',24),(200,35,'2026-01-22',NULL,NULL,'Hadir',24),(201,36,'2026-01-22',NULL,NULL,'Hadir',24),(202,37,'2026-01-22',NULL,NULL,'Hadir',24),(203,38,'2026-01-22',NULL,NULL,'Hadir',24),(204,39,'2026-01-22',NULL,NULL,'Hadir',24),(205,97,'2026-01-22',NULL,NULL,'Hadir',20),(206,98,'2026-01-22',NULL,NULL,'Hadir',20),(207,99,'2026-01-22',NULL,NULL,'Hadir',20),(208,100,'2026-01-22',NULL,NULL,'Hadir',20),(209,101,'2026-01-22',NULL,NULL,'Hadir',20),(210,102,'2026-01-22',NULL,NULL,'Hadir',20),(211,103,'2026-01-22',NULL,NULL,'Hadir',20),(212,104,'2026-01-22',NULL,NULL,'Hadir',20),(213,105,'2026-01-22',NULL,NULL,'Hadir',20),(214,106,'2026-01-22',NULL,NULL,'Hadir',20),(215,107,'2026-01-22',NULL,NULL,'Hadir',20),(216,108,'2026-01-22',NULL,NULL,'Hadir',20),(217,109,'2026-01-22',NULL,NULL,'Hadir',20),(218,110,'2026-01-22',NULL,NULL,'Hadir',20),(219,111,'2026-01-22',NULL,NULL,'Hadir',20),(220,112,'2026-01-22',NULL,NULL,'Hadir',20),(221,113,'2026-01-22',NULL,NULL,'Hadir',20),(222,114,'2026-01-22',NULL,NULL,'Hadir',20),(223,115,'2026-01-23',NULL,NULL,'Hadir',19),(224,116,'2026-01-23',NULL,NULL,'Hadir',19),(225,117,'2026-01-23',NULL,NULL,'Hadir',19),(226,118,'2026-01-23',NULL,NULL,'Hadir',19),(227,119,'2026-01-23',NULL,NULL,'Hadir',19),(228,120,'2026-01-23',NULL,NULL,'Hadir',19),(229,121,'2026-01-23',NULL,NULL,'Hadir',19),(230,122,'2026-01-23',NULL,NULL,'Sakit',19),(231,123,'2026-01-23',NULL,NULL,'Hadir',19),(232,124,'2026-01-23',NULL,NULL,'Hadir',19),(233,125,'2026-01-23',NULL,NULL,'Hadir',19),(234,126,'2026-01-23',NULL,NULL,'Hadir',19),(235,127,'2026-01-23',NULL,NULL,'Hadir',19),(236,128,'2026-01-23',NULL,NULL,'Hadir',19),(237,129,'2026-01-23',NULL,NULL,'Hadir',19),(238,130,'2026-01-23',NULL,NULL,'Izin',19),(239,131,'2026-01-23',NULL,NULL,'Hadir',19),(240,132,'2026-01-23',NULL,NULL,'Hadir',19),(241,133,'2026-01-23',NULL,NULL,'Hadir',19),(242,40,'2026-01-23',NULL,NULL,'Hadir',24),(243,41,'2026-01-23',NULL,NULL,'Hadir',24),(244,42,'2026-01-23',NULL,NULL,'Hadir',24),(245,43,'2026-01-23',NULL,NULL,'Hadir',24),(246,44,'2026-01-23',NULL,NULL,'Hadir',24),(247,45,'2026-01-23',NULL,NULL,'Hadir',24),(248,46,'2026-01-23',NULL,NULL,'Hadir',24),(249,47,'2026-01-23',NULL,NULL,'Hadir',24),(250,48,'2026-01-23',NULL,NULL,'Hadir',24),(251,49,'2026-01-23',NULL,NULL,'Sakit',24),(252,50,'2026-01-23',NULL,NULL,'Hadir',24),(253,51,'2026-01-23',NULL,NULL,'Hadir',24),(254,52,'2026-01-23',NULL,NULL,'Hadir',24),(255,53,'2026-01-23',NULL,NULL,'Hadir',24),(256,54,'2026-01-23',NULL,NULL,'Hadir',24),(257,55,'2026-01-23',NULL,NULL,'Hadir',24),(258,56,'2026-01-23',NULL,NULL,'Hadir',24),(259,57,'2026-01-23',NULL,NULL,'Hadir',24),(260,58,'2026-01-23',NULL,NULL,'Hadir',24),(261,59,'2026-01-23',NULL,NULL,'Hadir',24),(262,60,'2026-01-23',NULL,NULL,'Hadir',24),(263,61,'2026-01-23',NULL,NULL,'Hadir',24),(264,62,'2026-01-23',NULL,NULL,'Hadir',24),(265,63,'2026-01-23',NULL,NULL,'Hadir',24),(266,97,'2026-01-23',NULL,NULL,'Hadir',20),(267,98,'2026-01-23',NULL,NULL,'Hadir',20),(268,99,'2026-01-23',NULL,NULL,'Hadir',20),(269,100,'2026-01-23',NULL,NULL,'Hadir',20),(270,101,'2026-01-23',NULL,NULL,'Hadir',20),(271,102,'2026-01-23',NULL,NULL,'Hadir',20),(272,103,'2026-01-23',NULL,NULL,'Hadir',20),(273,104,'2026-01-23',NULL,NULL,'Hadir',20),(274,105,'2026-01-23',NULL,NULL,'Sakit',20),(275,106,'2026-01-23',NULL,NULL,'Hadir',20),(276,107,'2026-01-23',NULL,NULL,'Hadir',20),(277,108,'2026-01-23',NULL,NULL,'Hadir',20),(278,109,'2026-01-23',NULL,NULL,'Hadir',20),(279,110,'2026-01-23',NULL,NULL,'Hadir',20),(280,111,'2026-01-23',NULL,NULL,'Hadir',20),(281,112,'2026-01-23',NULL,NULL,'Hadir',20),(282,113,'2026-01-23',NULL,NULL,'Hadir',20),(283,114,'2026-01-23',NULL,NULL,'Hadir',20),(284,22,'2026-01-23',NULL,NULL,'Hadir',NULL),(285,23,'2026-01-23',NULL,NULL,'Hadir',NULL),(286,24,'2026-01-23',NULL,NULL,'Hadir',NULL),(287,25,'2026-01-23',NULL,NULL,'Hadir',NULL),(288,26,'2026-01-23',NULL,NULL,'Hadir',NULL),(289,27,'2026-01-23',NULL,NULL,'Hadir',NULL),(290,28,'2026-01-23',NULL,NULL,'Hadir',NULL),(291,30,'2026-01-23',NULL,NULL,'Hadir',NULL),(292,29,'2026-01-23',NULL,NULL,'Hadir',NULL),(293,31,'2026-01-23',NULL,NULL,'Hadir',NULL),(294,32,'2026-01-23',NULL,NULL,'Hadir',NULL),(295,33,'2026-01-23',NULL,NULL,'Hadir',NULL),(296,34,'2026-01-23',NULL,NULL,'Hadir',NULL),(297,35,'2026-01-23',NULL,NULL,'Hadir',NULL),(298,36,'2026-01-23',NULL,NULL,'Hadir',NULL),(299,37,'2026-01-23',NULL,NULL,'Hadir',NULL),(300,38,'2026-01-23',NULL,NULL,'Hadir',NULL),(301,39,'2026-01-23',NULL,NULL,'Hadir',NULL),(302,115,'2026-01-24',NULL,NULL,'Hadir',NULL),(303,116,'2026-01-24',NULL,NULL,'Hadir',NULL),(304,117,'2026-01-24',NULL,NULL,'Hadir',NULL),(305,118,'2026-01-24',NULL,NULL,'Izin',NULL),(306,119,'2026-01-24',NULL,NULL,'Hadir',NULL),(307,120,'2026-01-24',NULL,NULL,'Hadir',NULL),(308,121,'2026-01-24',NULL,NULL,'Hadir',NULL),(309,122,'2026-01-24',NULL,NULL,'Hadir',NULL),(310,123,'2026-01-24',NULL,NULL,'Hadir',NULL),(311,124,'2026-01-24',NULL,NULL,'Hadir',NULL),(312,125,'2026-01-24',NULL,NULL,'Hadir',NULL),(313,126,'2026-01-24',NULL,NULL,'Hadir',NULL),(314,127,'2026-01-24',NULL,NULL,'Hadir',NULL),(315,128,'2026-01-24',NULL,NULL,'Hadir',NULL),(316,129,'2026-01-24',NULL,NULL,'Hadir',NULL),(317,130,'2026-01-24',NULL,NULL,'Hadir',NULL),(318,131,'2026-01-24',NULL,NULL,'Hadir',NULL),(319,132,'2026-01-24',NULL,NULL,'Hadir',NULL),(320,133,'2026-01-24',NULL,NULL,'Hadir',NULL),(321,115,'2026-01-25',NULL,NULL,'Hadir',NULL),(322,116,'2026-01-25',NULL,NULL,'Hadir',NULL),(323,117,'2026-01-25',NULL,NULL,'Hadir',NULL),(324,118,'2026-01-25',NULL,NULL,'Hadir',NULL),(325,119,'2026-01-25',NULL,NULL,'Hadir',NULL),(326,120,'2026-01-25',NULL,NULL,'Hadir',NULL),(327,121,'2026-01-25',NULL,NULL,'Hadir',NULL),(328,122,'2026-01-25',NULL,NULL,'Hadir',NULL),(329,123,'2026-01-25',NULL,NULL,'Hadir',NULL),(330,124,'2026-01-25',NULL,NULL,'Hadir',NULL),(331,125,'2026-01-25',NULL,NULL,'Hadir',NULL),(332,126,'2026-01-25',NULL,NULL,'Hadir',NULL),(333,127,'2026-01-25',NULL,NULL,'Hadir',NULL),(334,128,'2026-01-25',NULL,NULL,'Hadir',NULL),(335,129,'2026-01-25',NULL,NULL,'Hadir',NULL),(336,130,'2026-01-25',NULL,NULL,'Hadir',NULL),(337,131,'2026-01-25',NULL,NULL,'Hadir',NULL),(338,132,'2026-01-25',NULL,NULL,'Hadir',NULL),(339,133,'2026-01-25',NULL,NULL,'Hadir',NULL),(340,22,'2026-01-25','11:38:41',NULL,'Hadir',NULL),(341,23,'2026-01-25',NULL,NULL,'Hadir',NULL),(342,25,'2026-01-25',NULL,NULL,'Hadir',NULL),(343,27,'2026-01-25',NULL,NULL,'Hadir',NULL),(344,26,'2026-01-25',NULL,NULL,'Hadir',NULL),(345,24,'2026-01-25',NULL,NULL,'Hadir',NULL),(346,100,'2026-01-25','17:53:38',NULL,'Hadir',NULL),(347,98,'2026-01-25','17:53:45',NULL,'Hadir',NULL),(348,97,'2026-01-25','17:53:55',NULL,'Hadir',NULL),(349,99,'2026-01-25','17:54:01',NULL,'Hadir',NULL),(350,101,'2026-01-25','17:54:22',NULL,'Hadir',NULL),(351,125,'2026-01-26',NULL,NULL,'Sakit',NULL),(352,126,'2026-01-26',NULL,NULL,'Hadir',NULL),(353,127,'2026-01-26',NULL,NULL,'Hadir',NULL),(354,128,'2026-01-26',NULL,NULL,'Hadir',NULL),(355,129,'2026-01-26',NULL,NULL,'Hadir',NULL),(356,130,'2026-01-26',NULL,NULL,'Hadir',NULL),(357,131,'2026-01-26',NULL,NULL,'Hadir',NULL),(358,132,'2026-01-26',NULL,NULL,'Hadir',NULL),(359,133,'2026-01-26',NULL,NULL,'Hadir',NULL),(360,115,'2026-01-26',NULL,NULL,'Alpa',NULL),(361,116,'2026-01-26',NULL,NULL,'Alpa',NULL),(362,117,'2026-01-26',NULL,NULL,'Hadir',NULL),(363,118,'2026-01-26',NULL,NULL,'Hadir',NULL),(364,119,'2026-01-26',NULL,NULL,'Hadir',NULL),(365,120,'2026-01-26',NULL,NULL,'Hadir',NULL),(366,121,'2026-01-26',NULL,NULL,'Hadir',NULL),(367,122,'2026-01-26',NULL,NULL,'Hadir',NULL),(368,123,'2026-01-26',NULL,NULL,'Hadir',NULL),(369,124,'2026-01-26',NULL,NULL,'Hadir',NULL),(370,22,'2026-01-27','08:19:59',NULL,'Hadir',NULL),(371,115,'2026-01-27','08:21:29',NULL,'Hadir',NULL),(372,116,'2026-01-27',NULL,NULL,'Hadir',NULL),(373,117,'2026-01-27',NULL,NULL,'Hadir',NULL),(374,118,'2026-01-27',NULL,NULL,'Hadir',NULL),(375,119,'2026-01-27',NULL,NULL,'Hadir',NULL),(376,120,'2026-01-27',NULL,NULL,'Hadir',NULL),(377,121,'2026-01-27',NULL,NULL,'Hadir',NULL),(378,122,'2026-01-27',NULL,NULL,'Hadir',NULL),(379,123,'2026-01-27',NULL,NULL,'Hadir',NULL),(380,124,'2026-01-27',NULL,NULL,'Izin',NULL),(381,125,'2026-01-27',NULL,NULL,'Hadir',NULL),(382,126,'2026-01-27',NULL,NULL,'Hadir',NULL),(383,127,'2026-01-27',NULL,NULL,'Hadir',NULL),(384,128,'2026-01-27',NULL,NULL,'Hadir',NULL),(385,129,'2026-01-27',NULL,NULL,'Hadir',NULL),(386,130,'2026-01-27',NULL,NULL,'Hadir',NULL),(387,131,'2026-01-27',NULL,NULL,'Hadir',NULL),(388,132,'2026-01-27',NULL,NULL,'Hadir',NULL),(389,133,'2026-01-27',NULL,NULL,'Hadir',NULL),(390,22,'2026-01-29',NULL,NULL,'Hadir',NULL),(391,23,'2026-01-29',NULL,NULL,'Hadir',NULL),(392,24,'2026-01-29',NULL,NULL,'Hadir',NULL),(393,25,'2026-01-29',NULL,NULL,'Hadir',NULL),(394,26,'2026-01-29',NULL,NULL,'Hadir',NULL),(395,27,'2026-01-29',NULL,NULL,'Hadir',NULL),(396,28,'2026-01-29',NULL,NULL,'Hadir',NULL),(397,30,'2026-01-29',NULL,NULL,'Sakit',NULL),(398,29,'2026-01-29',NULL,NULL,'Hadir',NULL),(399,31,'2026-01-29',NULL,NULL,'Izin',NULL),(400,32,'2026-01-29',NULL,NULL,'Hadir',NULL),(401,33,'2026-01-29',NULL,NULL,'Hadir',NULL),(402,34,'2026-01-29',NULL,NULL,'Hadir',NULL),(403,35,'2026-01-29',NULL,NULL,'Hadir',NULL),(404,36,'2026-01-29',NULL,NULL,'Hadir',NULL),(405,37,'2026-01-29',NULL,NULL,'Hadir',NULL),(406,38,'2026-01-29',NULL,NULL,'Hadir',NULL),(407,39,'2026-01-29',NULL,NULL,'Hadir',NULL),(408,115,'2026-01-29','19:46:30',NULL,'Hadir',NULL),(409,116,'2026-01-29',NULL,NULL,'Hadir',19),(410,117,'2026-01-29',NULL,NULL,'Hadir',19),(411,118,'2026-01-29',NULL,NULL,'Hadir',19),(412,119,'2026-01-29',NULL,NULL,'Hadir',19),(413,120,'2026-01-29',NULL,NULL,'Hadir',19),(414,121,'2026-01-29',NULL,NULL,'Hadir',19),(415,122,'2026-01-29',NULL,NULL,'Hadir',19),(416,123,'2026-01-29',NULL,NULL,'Hadir',19),(417,124,'2026-01-29',NULL,NULL,'Hadir',19),(418,125,'2026-01-29',NULL,NULL,'Hadir',19),(419,126,'2026-01-29',NULL,NULL,'Hadir',19),(420,127,'2026-01-29',NULL,NULL,'Hadir',19),(421,128,'2026-01-29',NULL,NULL,'Hadir',19),(422,129,'2026-01-29',NULL,NULL,'Hadir',19),(423,130,'2026-01-29',NULL,NULL,'Hadir',19),(424,131,'2026-01-29',NULL,NULL,'Hadir',19),(425,132,'2026-01-29',NULL,NULL,'Hadir',19),(426,133,'2026-01-29',NULL,NULL,'Hadir',19),(427,115,'2026-01-31',NULL,NULL,'Hadir',19),(428,116,'2026-01-31',NULL,NULL,'Hadir',19),(429,117,'2026-01-31',NULL,NULL,'Hadir',19),(430,118,'2026-01-31',NULL,NULL,'Hadir',19),(431,119,'2026-01-31',NULL,NULL,'Hadir',19),(432,120,'2026-01-31',NULL,NULL,'Hadir',19),(433,121,'2026-01-31',NULL,NULL,'Sakit',19),(434,122,'2026-01-31',NULL,NULL,'Hadir',19),(435,123,'2026-01-31',NULL,NULL,'Hadir',19),(436,124,'2026-01-31',NULL,NULL,'Hadir',19),(437,125,'2026-01-31',NULL,NULL,'Hadir',19),(438,126,'2026-01-31',NULL,NULL,'Hadir',19),(439,127,'2026-01-31',NULL,NULL,'Hadir',19),(440,128,'2026-01-31',NULL,NULL,'Hadir',19),(441,129,'2026-01-31',NULL,NULL,'Hadir',19),(442,130,'2026-01-31',NULL,NULL,'Hadir',19),(443,131,'2026-01-31',NULL,NULL,'Hadir',19),(444,132,'2026-01-31',NULL,NULL,'Izin',19),(445,133,'2026-01-31',NULL,NULL,'Hadir',19),(446,64,'2026-01-31',NULL,NULL,'Hadir',22),(447,65,'2026-01-31',NULL,NULL,'Hadir',22),(448,66,'2026-01-31',NULL,NULL,'Hadir',22),(449,67,'2026-01-31',NULL,NULL,'Hadir',22),(450,68,'2026-01-31',NULL,NULL,'Sakit',22),(451,69,'2026-01-31',NULL,NULL,'Hadir',22),(452,70,'2026-01-31',NULL,NULL,'Hadir',22),(453,71,'2026-01-31',NULL,NULL,'Hadir',22),(454,72,'2026-01-31',NULL,NULL,'Sakit',22),(455,73,'2026-01-31',NULL,NULL,'Hadir',22),(456,74,'2026-01-31',NULL,NULL,'Hadir',22),(457,75,'2026-01-31',NULL,NULL,'Hadir',22),(458,76,'2026-01-31',NULL,NULL,'Hadir',22),(459,77,'2026-01-31',NULL,NULL,'Izin',22),(460,78,'2026-01-31',NULL,NULL,'Hadir',22),(461,79,'2026-01-31',NULL,NULL,'Hadir',22),(462,80,'2026-01-31',NULL,NULL,'Hadir',22),(463,81,'2026-01-31',NULL,NULL,'Alpa',22),(464,82,'2026-01-31',NULL,NULL,'Hadir',22),(465,83,'2026-01-31',NULL,NULL,'Hadir',22);
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
  `status` enum('hadir','sakit','izin','alpa') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `keterangan` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `waktu_input` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_absensi`),
  KEY `id_guru` (`id_guru`),
  CONSTRAINT `tb_absensi_guru_ibfk_1` FOREIGN KEY (`id_guru`) REFERENCES `tb_guru` (`id_guru`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=38 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_absensi_guru`
--

LOCK TABLES `tb_absensi_guru` WRITE;
/*!40000 ALTER TABLE `tb_absensi_guru` DISABLE KEYS */;
INSERT INTO `tb_absensi_guru` VALUES (1,20,'2026-01-22','hadir','','2026-01-22 19:33:34'),(2,30,'2026-01-22','hadir','','2026-01-22 19:33:34'),(3,19,'2026-01-22','izin','pindah hari','2026-01-22 15:06:43'),(4,24,'2026-01-22','hadir','','2026-01-22 15:36:35'),(5,29,'2026-01-22','hadir','','2026-01-22 15:36:08'),(6,23,'2026-01-22','sakit','sakit panas','2026-01-22 15:36:35'),(7,28,'2026-01-22','hadir','','2026-01-22 15:36:35'),(8,22,'2026-01-22','hadir','','2026-01-22 15:36:47'),(9,26,'2026-01-22','hadir','','2026-01-22 15:36:47'),(10,21,'2026-01-22','alpa','','2026-01-22 19:25:33'),(11,27,'2026-01-22','hadir','','2026-01-22 19:25:33'),(12,18,'2026-01-22','hadir','','2026-01-22 19:33:33'),(13,25,'2026-01-22','hadir','','2026-01-22 19:33:34'),(14,19,'2026-01-23','hadir','','2026-01-23 04:17:25'),(15,24,'2026-01-23','hadir','','2026-01-23 05:59:16'),(16,20,'2026-01-23','hadir','','2026-01-23 06:07:59'),(17,20,'2026-01-24','hadir','','2026-01-24 19:38:58'),(18,30,'2026-01-24','hadir','','2026-01-24 19:38:58'),(19,19,'2026-01-24','hadir','','2026-01-24 19:38:59'),(20,24,'2026-01-24','hadir','','2026-01-24 20:30:41'),(21,29,'2026-01-24','hadir','','2026-01-24 20:30:41'),(22,20,'2026-01-25','hadir','','2026-01-25 13:33:56'),(23,30,'2026-01-25','alpa','','2026-01-25 13:33:56'),(24,19,'2026-01-25','hadir','','2026-01-25 04:27:26'),(25,18,'2026-01-25','hadir','','2026-01-25 13:33:55'),(26,21,'2026-01-25','hadir','','2026-01-25 13:33:33'),(27,25,'2026-01-25','alpa','','2026-01-25 13:33:56'),(28,22,'2026-01-25','hadir','','2026-01-25 16:14:46'),(29,23,'2026-01-25','hadir','','2026-01-25 16:17:54'),(30,19,'2026-01-26','hadir','','2026-01-26 07:20:17'),(31,20,'2026-01-27','alpa','','2026-01-27 10:07:02'),(32,30,'2026-01-27','alpa','','2026-01-27 10:07:02'),(33,19,'2026-01-27','hadir','','2026-01-27 10:07:02'),(34,19,'2026-01-29','hadir','','2026-01-29 19:49:35'),(35,19,'2026-01-30','hadir','','2026-01-30 21:04:57'),(36,19,'2026-01-31','hadir','','2026-01-31 13:23:03'),(37,22,'2026-01-31','hadir','','2026-01-31 13:47:18');
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
) ENGINE=InnoDB AUTO_INCREMENT=642 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_activity_log`
--

LOCK TABLES `tb_activity_log` WRITE;
/*!40000 ALTER TABLE `tb_activity_log` DISABLE KEYS */;
INSERT INTO `tb_activity_log` VALUES (575,'admin','Login','User logged in successfully','::1','2026-01-30 11:04:15'),(576,'admin','Logout','User logged out from admin session','::1','2026-01-30 11:28:07'),(577,'kepala','Login','User logged in successfully','::1','2026-01-30 11:28:18'),(578,'kepala','Logout','User logged out from kepala_madrasah session','::1','2026-01-30 12:14:37'),(579,'kepala','Login','User logged in successfully','::1','2026-01-30 12:14:39'),(580,'kepala','Logout','User logged out from kepala_madrasah session','::1','2026-01-30 12:22:12'),(581,'kepala','Login','User logged in successfully','::1','2026-01-30 12:22:18'),(582,'kepala','Logout','User logged out from kepala_madrasah session','::1','2026-01-30 12:45:10'),(583,'5436757658200002','Login','Teacher logged in successfully using NUPTK','::1','2026-01-30 12:45:19'),(584,'5436757658200002','Login','Teacher logged in successfully using NUPTK','::1','2026-01-30 13:33:04'),(585,'5436757658200002','Login','Teacher logged in successfully using NUPTK','::1','2026-01-30 13:35:58'),(586,'5436757658200002','Login','Teacher logged in successfully using NUPTK','::1','2026-01-30 13:36:05'),(587,'Nur Huda, S.Pd.I.','Absensi Guru','Nur Huda, S.Pd.I. (Wali) memperbarui kehadiran: hadir','::1','2026-01-30 14:04:57'),(588,'5436757658200002','Logout','User logged out from wali session','::1','2026-01-30 23:40:41'),(589,'admin','Login','User logged in successfully','::1','2026-01-30 23:40:45'),(590,'admin','Update Mata Pelajaran','Update mapel ID 18 menjadi Asmaul Husna dan Tadarrus (A) tanpa KKTP','::1','2026-01-30 23:49:16'),(591,'admin','Update Mata Pelajaran','Update mapel ID 18 menjadi Asmaul Husna dan Tadarrus (A) tanpa KKTP','::1','2026-01-30 23:51:50'),(592,'admin','Update Global KKTP','Update global KKTP 70 untuk SEMUA mapel','::1','2026-01-30 23:52:32'),(593,'admin','Update Mata Pelajaran','Update mapel ID 18 menjadi Asmaul Husna dan Tadarrus (A) tanpa KKTP','::1','2026-01-30 23:52:41'),(594,'admin','Update Mata Pelajaran','Update mapel ID 20 menjadi Istirahat I (C) tanpa KKTP','::1','2026-01-30 23:52:54'),(595,'admin','Update Mata Pelajaran','Update mapel ID 21 menjadi Istirahat II (D) tanpa KKTP','::1','2026-01-30 23:53:03'),(596,'admin','Update Mata Pelajaran','Update mapel ID 19 menjadi Upacara Bendera (B) tanpa KKTP','::1','2026-01-30 23:53:15'),(597,'admin','Update Mata Pelajaran','Update mapel ID 19 menjadi Upacara Bendera (B) tanpa KKTP','::1','2026-01-30 23:54:09'),(598,'admin','Update Global KKTP','Update global KKTP 70 untuk SEMUA mapel','::1','2026-01-30 23:54:23'),(599,'admin','Update Mata Pelajaran','Update mapel ID 18 menjadi Asmaul Husna dan Tadarrus (A) tanpa KKTP','::1','2026-01-30 23:54:31'),(600,'admin','Update Mata Pelajaran','Update mapel ID 22 menjadi Ekstrakurikuler (18) tanpa KKTP','::1','2026-01-30 23:54:46'),(601,'admin','Update Mata Pelajaran','Update mapel ID 20 menjadi Istirahat I (C) tanpa KKTP','::1','2026-01-30 23:54:54'),(602,'admin','Update Mata Pelajaran','Update mapel ID 21 menjadi Istirahat II (D) tanpa KKTP','::1','2026-01-30 23:55:02'),(603,'admin','Update Mata Pelajaran','Update mapel ID 19 menjadi Upacara Bendera (B) tanpa KKTP','::1','2026-01-30 23:55:10'),(604,'admin','Logout','User logged out from admin session','::1','2026-01-30 23:55:22'),(605,'5436757658200002','Login','Teacher logged in successfully using NUPTK','::1','2026-01-30 23:55:25'),(606,'5436757658200002','Logout','User logged out from wali session','::1','2026-01-31 04:56:59'),(607,'kepala','Login','User logged in successfully','::1','2026-01-31 04:57:03'),(608,'kepala','Logout','User logged out from kepala_madrasah session','::1','2026-01-31 05:47:26'),(609,'5436757658200002','Login','Teacher logged in successfully using NUPTK','::1','2026-01-31 05:47:30'),(610,'5436757658200002','Logout','User logged out from wali session','::1','2026-01-31 06:00:24'),(611,'admin','Login','User logged in successfully','::1','2026-01-31 06:00:27'),(612,'admin','Login','User logged in successfully','::1','2026-01-31 06:18:48'),(613,'admin','Logout','User logged out from admin session','::1','2026-01-31 06:22:45'),(614,'5436757658200002','Login','Teacher logged in successfully using NUPTK','::1','2026-01-31 06:22:48'),(615,'5436757658200002','Input Absensi','Wali 5436757658200002 melakukan input absensi harian kelas ID: 6 untuk 19 siswa','::1','2026-01-31 06:22:56'),(616,'Nur Huda, S.Pd.I.','Absensi Guru','Nur Huda, S.Pd.I. (Wali) memperbarui kehadiran: hadir','::1','2026-01-31 06:23:03'),(617,'5436757658200002','Logout','User logged out from wali session','::1','2026-01-31 06:23:17'),(618,'kepala','Login','User logged in successfully','::1','2026-01-31 06:23:20'),(619,'kepala','Logout','User logged out from kepala_madrasah session','::1','2026-01-31 06:45:59'),(620,'5436757658200002','Login','Teacher logged in successfully using NUPTK','::1','2026-01-31 06:46:06'),(621,'5436757658200002','Input Absensi','Wali 5436757658200002 melakukan input absensi harian kelas ID: 6 untuk 19 siswa','::1','2026-01-31 06:46:22'),(622,'5436757658200002','Logout','User logged out from wali session','::1','2026-01-31 06:46:29'),(623,'kepala','Login','User logged in successfully','::1','2026-01-31 06:46:33'),(624,'kepala','Logout','User logged out from kepala_madrasah session','::1','2026-01-31 06:47:07'),(625,'9547746647110022','Login','Teacher logged in successfully using NUPTK','::1','2026-01-31 06:47:15'),(626,'Ali Yasin, S.Pd.I','Absensi Guru','Ali Yasin, S.Pd.I (Wali) memperbarui kehadiran: hadir','::1','2026-01-31 06:47:18'),(627,'9547746647110022','Input Absensi','Wali 9547746647110022 melakukan input absensi harian kelas ID: 3 untuk 20 siswa','::1','2026-01-31 06:47:39'),(628,'9547746647110022','Logout','User logged out from wali session','::1','2026-01-31 06:47:44'),(629,'admin','Login','User logged in successfully','::1','2026-01-31 06:47:48'),(630,'admin','Login','User logged in successfully','::1','2026-01-31 07:03:32'),(631,'admin','Login','User logged in successfully','::1','2026-01-31 07:22:23'),(632,'admin','Logout','User logged out from admin session','::1','2026-01-31 07:25:41'),(633,'admin','Login','User logged in successfully','::1','2026-01-31 07:26:06'),(634,'admin','Logout','User logged out from admin session','::1','2026-01-31 07:26:19'),(635,'3146588936','Login','Student logged in successfully using NISN','::1','2026-01-31 07:26:25'),(636,'3146588936','Logout','User logged out from siswa session','::1','2026-01-31 07:35:54'),(637,'admin','Login','User logged in successfully','::1','2026-01-31 07:35:57'),(638,'admin','Logout','User logged out from admin session','::1','2026-01-31 07:36:14'),(639,'0146901301','Login','Student logged in successfully using NISN','::1','2026-01-31 07:36:19'),(640,'0146901301','Logout','User logged out from siswa session','::1','2026-01-31 07:36:34'),(641,'admin','Login','User logged in successfully','::1','2026-01-31 07:36:36');
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
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_backup_restore`
--

LOCK TABLES `tb_backup_restore` WRITE;
/*!40000 ALTER TABLE `tb_backup_restore` DISABLE KEYS */;
INSERT INTO `tb_backup_restore` VALUES (8,'backup_2026-01-26_21-19-37.sql','2026-01-26 21:19:39','61.46 KB','Backup manual'),(9,'backup_2026-01-27_11-27-46.sql','2026-01-27 11:27:49','86.36 KB','Backup manual');
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
  `email` varchar(100) DEFAULT NULL,
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
INSERT INTO `tb_guru` VALUES (18,'K','Abdul Ghofur, S.Pd.I',NULL,'2444764667200003','Jepara','1986-11-12','Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"5\"]',NULL),(19,'I','Nur Huda, S.Pd.I.',NULL,'5436757658200002','Jepara','1979-01-04','Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"6\"]','guru_19_1769260235.jpg'),(20,'A','Ah. Mustaqim Isom, A.Ma.',NULL,'7841746648200002','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"5\",\"6\"]',NULL),(21,'M','Alfina Martha Sintya, S.Pd.',NULL,'33200111223','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"4\"]',NULL),(22,'E','Ali Yasin, S.Pd.I',NULL,'9547746647110022','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"3\"]',NULL),(23,'H','Hamidah, A.Ma.',NULL,'4444747649200002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"2\"]',NULL),(24,'F','Indasah, A.Ma.',NULL,'2640755657300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"1\",\"3\"]',NULL),(25,'L','Khoiruddin, S.Pd.',NULL,'ID20318581190001','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"5\"]',NULL),(26,'C','Musri`ah, S.Pd.I',NULL,'6956748651300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"3\"]',NULL),(27,'D','Nanik Purwati, S.Pd.I',NULL,'6556755656300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"4\"]',NULL),(28,'G','Nur Hidah, S.Pd.I.',NULL,'7357760661300003','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"2\"]',NULL),(29,'J','Zama`ah, S.Pd.I.',NULL,'8041756657300003','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"1\"]',NULL),(30,'B','Muhamad Junaedi',NULL,'8552750652200002','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"5\",\"6\"]',NULL);
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
) ENGINE=InnoDB AUTO_INCREMENT=392 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_jadwal_pelajaran`
--

LOCK TABLES `tb_jadwal_pelajaran` WRITE;
/*!40000 ALTER TABLE `tb_jadwal_pelajaran` DISABLE KEYS */;
INSERT INTO `tb_jadwal_pelajaran` VALUES (6,1,'Sabtu','A',18,29,'Reguler','2026-01-26 11:42:51','2026-01-26 11:42:56'),(7,1,'Sabtu','1',8,29,'Reguler','2026-01-26 11:43:04','2026-01-27 01:24:37'),(8,1,'Sabtu','2',8,29,'Reguler','2026-01-26 11:50:18','2026-01-27 01:24:41'),(9,1,'Sabtu','3',6,29,'Reguler','2026-01-26 11:50:40','2026-01-27 01:24:52'),(13,1,'Ahad','A',18,29,'Reguler','2026-01-26 11:56:20','2026-01-26 23:01:25'),(18,6,'Sabtu','A',18,19,'Reguler','2026-01-26 12:31:19','2026-01-26 12:31:23'),(19,6,'Sabtu','1',1,19,'Reguler','2026-01-26 12:31:28','2026-01-26 12:31:35'),(20,6,'Sabtu','2',1,19,'Reguler','2026-01-26 12:31:41','2026-01-26 12:31:47'),(21,6,'Sabtu','3',1,19,'Reguler','2026-01-26 12:31:51','2026-01-26 12:31:57'),(23,6,'Sabtu','4',1,19,'Reguler','2026-01-26 12:32:15','2026-01-26 12:32:24'),(24,6,'Sabtu','5',3,19,'Reguler','2026-01-26 12:32:29','2026-01-26 12:32:33'),(25,6,'Sabtu','6',3,19,'Reguler','2026-01-26 12:32:38','2026-01-26 12:32:43'),(26,6,'Sabtu','C',21,19,'Reguler','2026-01-26 12:32:59','2026-01-26 12:41:45'),(27,6,'Sabtu','7',2,19,'Reguler','2026-01-26 12:33:13','2026-01-26 12:33:18'),(28,6,'Sabtu','8',2,19,'Reguler','2026-01-26 12:33:23','2026-01-26 12:33:27'),(29,6,'Sabtu','9',2,19,'Reguler','2026-01-26 12:33:32','2026-01-26 12:33:36'),(30,1,'Senin','A',19,29,'Reguler','2026-01-26 12:37:36','2026-01-27 02:04:20'),(32,6,'Sabtu','B',20,19,'Reguler','2026-01-26 12:41:33','2026-01-26 12:41:41'),(33,1,'Sabtu','B',20,29,'Reguler','2026-01-26 12:41:56','2026-01-26 12:42:03'),(35,6,'Ahad','A',18,19,'Reguler','2026-01-26 13:41:51','2026-01-26 13:45:12'),(36,6,'Ahad','1',17,19,'Reguler','2026-01-26 13:42:02','2026-01-26 13:45:12'),(37,6,'Ahad','2',17,19,'Reguler','2026-01-26 13:42:15','2026-01-26 13:45:12'),(38,6,'Ahad','3',17,19,'Reguler','2026-01-26 13:42:28','2026-01-26 13:45:12'),(39,6,'Ahad','B',20,19,'Reguler','2026-01-26 13:43:23','2026-01-26 13:45:12'),(40,6,'Ahad','4',22,19,'Reguler','2026-01-26 13:43:40','2026-01-26 13:45:20'),(41,6,'Ahad','5',22,19,'Reguler','2026-01-26 13:45:25','2026-01-26 13:45:48'),(42,6,'Ahad','6',22,19,'Reguler','2026-01-26 13:45:36','2026-01-26 13:45:57'),(43,6,'Senin','A',19,19,'Reguler','2026-01-26 13:46:13','2026-01-26 13:46:37'),(44,6,'Senin','1',6,19,'Reguler','2026-01-26 13:46:27','2026-01-26 13:46:44'),(45,6,'Senin','2',6,19,'Reguler','2026-01-26 13:46:51','2026-01-26 13:47:02'),(46,6,'Senin','3',6,19,'Reguler','2026-01-26 13:47:07','2026-01-26 13:47:16'),(47,6,'Senin','B',20,19,'Reguler','2026-01-26 13:47:22','2026-01-26 13:47:30'),(48,6,'Senin','4',7,19,'Reguler','2026-01-26 13:47:44','2026-01-26 13:47:51'),(49,6,'Senin','5',7,19,'Reguler','2026-01-26 13:47:56','2026-01-26 13:48:02'),(50,6,'Senin','6',1,19,'Reguler','2026-01-26 13:48:07','2026-01-26 13:48:17'),(51,6,'Senin','C',21,19,'Reguler','2026-01-26 13:48:24','2026-01-26 13:48:33'),(52,6,'Senin','7',1,19,'Reguler','2026-01-26 13:48:37','2026-01-26 13:48:47'),(53,6,'Senin','8',11,19,'Reguler','2026-01-26 13:48:51','2026-01-26 13:49:06'),(54,6,'Senin','9',11,19,'Reguler','2026-01-26 13:49:11','2026-01-26 13:49:18'),(55,6,'Selasa','A',18,19,'Reguler','2026-01-26 13:49:33','2026-01-26 13:49:40'),(56,6,'Selasa','1',8,19,'Reguler','2026-01-26 13:49:45','2026-01-26 13:49:54'),(57,6,'Selasa','2',8,19,'Reguler','2026-01-26 13:49:59','2026-01-26 13:50:06'),(58,6,'Selasa','3',8,19,'Reguler','2026-01-26 13:50:10','2026-01-26 13:50:17'),(59,6,'Selasa','B',20,19,'Reguler','2026-01-26 13:50:22','2026-01-26 13:50:30'),(60,6,'Selasa','4',5,19,'Reguler','2026-01-26 13:50:34','2026-01-26 13:50:50'),(61,6,'Selasa','5',5,19,'Reguler','2026-01-26 13:50:56','2026-01-26 13:51:10'),(62,6,'Selasa','6',5,19,'Reguler','2026-01-26 13:51:15','2026-01-26 13:51:22'),(63,6,'Selasa','C',21,19,'Reguler','2026-01-26 13:51:31','2026-01-26 13:51:37'),(64,6,'Selasa','7',7,19,'Reguler','2026-01-26 13:51:44','2026-01-26 13:51:55'),(65,6,'Selasa','8',7,19,'Reguler','2026-01-26 13:51:59','2026-01-26 13:52:05'),(66,6,'Selasa','9',7,19,'Reguler','2026-01-26 13:52:10','2026-01-26 13:52:16'),(67,6,'Rabu','A',18,30,'Reguler','2026-01-26 13:52:31','2026-01-26 13:53:00'),(70,6,'Rabu','1',9,30,'Reguler','2026-01-26 13:55:32','2026-01-26 13:55:40'),(71,6,'Rabu','2',9,30,'Reguler','2026-01-26 13:55:45','2026-01-26 13:56:05'),(72,6,'Rabu','3',9,30,'Reguler','2026-01-26 13:56:13','2026-01-26 13:56:19'),(73,6,'Rabu','B',20,30,'Reguler','2026-01-26 13:56:26','2026-01-26 13:56:32'),(74,6,'Kamis','A',18,20,'Reguler','2026-01-26 13:56:46','2026-01-26 13:56:51'),(75,6,'Rabu','4',9,30,'Reguler','2026-01-26 13:58:27','2026-01-26 13:58:36'),(76,6,'Rabu','5',9,30,'Reguler','2026-01-26 13:58:40','2026-01-26 13:58:46'),(77,6,'Rabu','6',9,30,'Reguler','2026-01-26 13:58:55','2026-01-26 13:59:01'),(78,6,'Rabu','C',21,30,'Reguler','2026-01-26 13:59:06','2026-01-26 13:59:12'),(79,6,'Rabu','7',10,30,'Reguler','2026-01-26 13:59:17','2026-01-26 13:59:26'),(80,6,'Rabu','8',10,30,'Reguler','2026-01-26 14:00:19','2026-01-26 14:00:25'),(81,6,'Rabu','9',10,30,'Reguler','2026-01-26 14:00:29','2026-01-26 14:00:35'),(82,6,'Kamis','1',4,20,'Reguler','2026-01-26 14:00:43','2026-01-26 14:00:49'),(83,6,'Kamis','2',4,20,'Reguler','2026-01-26 14:00:54','2026-01-26 14:00:59'),(84,6,'Kamis','3',13,20,'Reguler','2026-01-26 14:01:08','2026-01-26 14:01:16'),(85,6,'Kamis','B',20,20,'Reguler','2026-01-26 14:01:23','2026-01-26 14:01:30'),(86,6,'Kamis','4',16,20,'Reguler','2026-01-26 14:01:41','2026-01-26 14:01:50'),(87,6,'Kamis','5',16,20,'Reguler','2026-01-26 14:01:55','2026-01-26 14:02:01'),(88,6,'Kamis','6',12,20,'Reguler','2026-01-26 14:02:17','2026-01-26 14:02:23'),(89,6,'Kamis','C',21,20,'Reguler','2026-01-26 14:02:29','2026-01-26 14:02:43'),(90,6,'Kamis','7',12,20,'Reguler','2026-01-26 14:02:36','2026-01-26 14:02:49'),(91,6,'Kamis','8',15,20,'Reguler','2026-01-26 14:02:53','2026-01-26 14:02:59'),(92,6,'Kamis','9',15,20,'Reguler','2026-01-26 14:03:04','2026-01-26 14:03:10'),(93,1,'Sabtu','1',3,29,'Ramadhan','2026-01-26 14:05:16','2026-01-26 14:05:25'),(94,1,'Sabtu','4',6,29,'Reguler','2026-01-27 01:24:57','2026-01-27 01:25:06'),(95,1,'Sabtu','5',1,29,'Reguler','2026-01-27 01:25:28','2026-01-27 01:25:58'),(96,1,'Sabtu','6',1,29,'Reguler','2026-01-27 01:26:03','2026-01-27 01:26:09'),(97,1,'Ahad','1',17,29,'Reguler','2026-01-27 01:26:25','2026-01-27 01:26:32'),(98,1,'Ahad','2',17,29,'Reguler','2026-01-27 01:26:38','2026-01-27 01:26:45'),(99,1,'Ahad','3',17,29,'Reguler','2026-01-27 01:26:50','2026-01-27 01:26:55'),(100,1,'Ahad','B',20,29,'Reguler','2026-01-27 01:27:00','2026-01-27 01:27:06'),(101,1,'Ahad','4',22,29,'Reguler','2026-01-27 01:27:13','2026-01-27 01:27:19'),(102,1,'Ahad','5',22,29,'Reguler','2026-01-27 01:27:25','2026-01-27 01:27:30'),(103,1,'Ahad','6',22,29,'Reguler','2026-01-27 01:27:36','2026-01-27 01:27:41'),(104,1,'Senin','1',4,29,'Reguler','2026-01-27 02:04:37','2026-01-27 02:04:41'),(105,1,'Senin','2',4,29,'Reguler','2026-01-27 02:04:45','2026-01-27 02:04:49'),(106,1,'Senin','3',14,29,'Reguler','2026-01-27 02:04:55','2026-01-27 02:06:40'),(107,1,'Senin','B',20,29,'Reguler','2026-01-27 02:06:53','2026-01-27 02:07:02'),(108,1,'Senin','4',1,29,'Reguler','2026-01-27 02:07:07','2026-01-27 02:07:15'),(109,1,'Senin','5',1,29,'Reguler','2026-01-27 02:07:21','2026-01-27 02:07:27'),(110,1,'Senin','6',1,29,'Reguler','2026-01-27 02:07:31','2026-01-27 02:07:39'),(111,1,'Selasa','A',18,29,'Reguler','2026-01-27 02:10:46','2026-01-27 02:10:50'),(112,1,'Selasa','1',9,29,'Reguler','2026-01-27 02:10:55','2026-01-27 02:11:10'),(113,1,'Selasa','2',9,29,'Reguler','2026-01-27 02:11:16','2026-01-27 02:11:23'),(114,1,'Selasa','3',9,29,'Reguler','2026-01-27 02:13:58','2026-01-27 02:14:05'),(115,1,'Selasa','B',20,29,'Reguler','2026-01-27 02:14:10','2026-01-27 02:14:16'),(116,1,'Selasa','4',9,29,'Reguler','2026-01-27 02:14:22','2026-01-27 02:14:32'),(117,1,'Selasa','5',1,29,'Reguler','2026-01-27 02:14:36','2026-01-27 02:14:41'),(118,1,'Selasa','6',1,29,'Reguler','2026-01-27 02:14:45','2026-01-27 02:14:50'),(119,1,'Rabu','A',18,24,'Reguler','2026-01-27 02:15:08','2026-01-27 02:15:12'),(120,1,'Rabu','1',3,24,'Reguler','2026-01-27 02:16:32','2026-01-27 02:16:36'),(121,1,'Rabu','2',3,24,'Reguler','2026-01-27 02:16:40','2026-01-27 02:16:44'),(122,1,'Rabu','3',15,24,'Reguler','2026-01-27 02:16:52','2026-01-27 02:16:59'),(123,1,'Rabu','B',20,24,'Reguler','2026-01-27 02:17:11','2026-01-27 02:17:17'),(124,1,'Rabu','4',15,24,'Reguler','2026-01-27 02:17:26','2026-01-27 02:17:32'),(125,1,'Rabu','5',16,24,'Reguler','2026-01-27 02:17:36','2026-01-27 02:17:47'),(126,1,'Rabu','6',16,24,'Reguler','2026-01-27 02:17:53','2026-01-27 02:17:59'),(127,1,'Kamis','A',18,29,'Reguler','2026-01-27 02:18:20','2026-01-27 02:18:25'),(128,1,'Kamis','1',2,29,'Reguler','2026-01-27 02:18:30','2026-01-27 02:18:39'),(129,1,'Kamis','2',2,29,'Reguler','2026-01-27 02:18:44','2026-01-27 02:18:48'),(130,1,'Kamis','3',11,29,'Reguler','2026-01-27 02:18:52','2026-01-27 02:18:58'),(131,1,'Kamis','B',20,29,'Reguler','2026-01-27 02:19:02','2026-01-27 02:19:09'),(132,1,'Kamis','4',11,29,'Reguler','2026-01-27 02:19:14','2026-01-27 02:19:24'),(133,1,'Kamis','5',9,29,'Reguler','2026-01-27 02:19:32','2026-01-27 02:19:41'),(134,1,'Kamis','6',9,29,'Reguler','2026-01-27 02:19:45','2026-01-27 02:19:51'),(135,2,'Sabtu','A',18,28,'Reguler','2026-01-27 02:22:17','2026-01-27 02:22:34'),(136,2,'Sabtu','1',8,28,'Reguler','2026-01-27 02:22:39','2026-01-27 02:22:46'),(137,2,'Sabtu','2',8,28,'Reguler','2026-01-27 02:22:52','2026-01-27 02:22:58'),(138,2,'Sabtu','3',1,28,'Reguler','2026-01-27 02:23:02','2026-01-27 02:23:11'),(139,2,'Sabtu','B',20,28,'Reguler','2026-01-27 02:23:18','2026-01-27 02:23:24'),(140,2,'Sabtu','4',1,28,'Reguler','2026-01-27 02:23:44','2026-01-27 02:23:51'),(141,2,'Sabtu','5',6,28,'Reguler','2026-01-27 02:23:55','2026-01-27 02:24:07'),(142,2,'Sabtu','6',6,28,'Reguler','2026-01-27 02:24:11','2026-01-27 02:24:17'),(143,2,'Senin','A',19,28,'Reguler','2026-01-27 02:24:57','2026-01-27 02:25:04'),(144,2,'Ahad','A',18,28,'Reguler','2026-01-27 02:25:18','2026-01-27 02:25:23'),(145,2,'Ahad','1',17,28,'Reguler','2026-01-27 02:25:29','2026-01-27 02:25:40'),(146,2,'Ahad','2',17,28,'Reguler','2026-01-27 02:25:45','2026-01-27 02:25:50'),(147,2,'Ahad','3',17,28,'Reguler','2026-01-27 02:25:54','2026-01-27 02:25:59'),(148,2,'Ahad','B',20,28,'Reguler','2026-01-27 02:26:03','2026-01-27 02:26:09'),(149,2,'Ahad','4',22,28,'Reguler','2026-01-27 02:26:16','2026-01-27 02:26:26'),(150,2,'Ahad','5',22,28,'Reguler','2026-01-27 02:26:30','2026-01-27 02:26:35'),(151,2,'Ahad','6',22,28,'Reguler','2026-01-27 02:26:40','2026-01-27 02:26:45'),(152,2,'Selasa','A',18,28,'Reguler','2026-01-27 02:27:55','2026-01-27 02:28:01'),(153,2,'Rabu','A',18,23,'Reguler','2026-01-27 02:47:13','2026-01-27 03:06:14'),(154,2,'Kamis','A',18,23,'Reguler','2026-01-27 02:47:26','2026-01-27 03:06:16'),(155,2,'Senin','1',9,28,'Reguler','2026-01-27 03:07:52','2026-01-27 03:07:59'),(156,2,'Senin','2',9,28,'Reguler','2026-01-27 03:08:04','2026-01-27 03:08:10'),(157,2,'Senin','3',1,28,'Reguler','2026-01-27 03:08:18','2026-01-27 03:08:23'),(158,2,'Senin','B',20,28,'Reguler','2026-01-27 03:08:27','2026-01-27 03:08:33'),(159,2,'Senin','4',1,28,'Reguler','2026-01-27 03:08:38','2026-01-27 03:08:48'),(160,2,'Senin','5',10,28,'Reguler','2026-01-27 03:08:58','2026-01-27 03:09:05'),(161,2,'Senin','6',10,28,'Reguler','2026-01-27 03:09:09','2026-01-27 03:09:15'),(162,2,'Selasa','1',9,28,'Reguler','2026-01-27 03:09:29','2026-01-27 03:09:34'),(163,2,'Selasa','2',9,28,'Reguler','2026-01-27 03:09:39','2026-01-27 03:09:44'),(164,2,'Selasa','3',1,28,'Reguler','2026-01-27 03:09:53','2026-01-27 03:10:00'),(165,2,'Selasa','B',20,28,'Reguler','2026-01-27 03:10:05','2026-01-27 03:10:12'),(166,2,'Selasa','4',1,28,'Reguler','2026-01-27 03:10:17','2026-01-27 03:10:26'),(167,2,'Selasa','5',11,28,'Reguler','2026-01-27 03:10:32','2026-01-27 03:10:38'),(168,2,'Selasa','6',11,28,'Reguler','2026-01-27 03:10:43','2026-01-27 03:10:47'),(169,2,'Rabu','1',2,23,'Reguler','2026-01-27 03:10:59','2026-01-27 03:11:05'),(170,2,'Rabu','2',2,23,'Reguler','2026-01-27 03:11:10','2026-01-27 03:11:15'),(171,2,'Rabu','3',16,23,'Reguler','2026-01-27 03:11:21','2026-01-27 03:11:29'),(172,2,'Rabu','B',20,23,'Reguler','2026-01-27 03:11:33','2026-01-27 03:11:38'),(173,2,'Rabu','4',16,23,'Reguler','2026-01-27 03:11:43','2026-01-27 03:13:42'),(174,2,'Rabu','5',15,23,'Reguler','2026-01-27 03:13:47','2026-01-27 03:13:52'),(175,2,'Rabu','6',15,23,'Reguler','2026-01-27 03:13:56','2026-01-27 03:14:01'),(176,2,'Kamis','1',4,23,'Reguler','2026-01-27 03:16:32','2026-01-27 03:16:36'),(177,2,'Kamis','2',4,23,'Reguler','2026-01-27 03:16:41','2026-01-27 03:16:45'),(178,2,'Kamis','3',14,23,'Reguler','2026-01-27 03:16:51','2026-01-27 03:16:58'),(179,2,'Kamis','B',20,23,'Reguler','2026-01-27 03:17:03','2026-01-27 03:17:08'),(180,2,'Kamis','4',14,23,'Reguler','2026-01-27 03:17:20','2026-01-27 03:17:25'),(181,2,'Kamis','5',3,23,'Reguler','2026-01-27 03:17:29','2026-01-27 03:17:35'),(182,2,'Kamis','6',3,23,'Reguler','2026-01-27 03:17:39','2026-01-27 03:17:43'),(183,3,'Sabtu','A',18,24,'Reguler','2026-01-27 03:18:52','2026-01-27 03:20:43'),(184,3,'Ahad','A',18,22,'Reguler','2026-01-27 03:19:06','2026-01-27 03:19:09'),(185,3,'Senin','A',19,26,'Reguler','2026-01-27 03:19:19','2026-01-27 03:32:26'),(186,3,'Selasa','A',18,22,'Reguler','2026-01-27 03:19:39','2026-01-27 03:19:43'),(187,3,'Rabu','A',18,22,'Reguler','2026-01-27 03:20:00','2026-01-27 03:20:14'),(188,3,'Kamis','A',18,22,'Reguler','2026-01-27 03:20:08','2026-01-27 03:20:16'),(189,3,'Sabtu','1',5,24,'Reguler','2026-01-27 03:20:49','2026-01-27 03:21:06'),(190,3,'Sabtu','2',5,24,'Reguler','2026-01-27 03:21:12','2026-01-27 03:21:17'),(191,3,'Sabtu','3',3,24,'Reguler','2026-01-27 03:21:26','2026-01-27 03:21:31'),(192,3,'Sabtu','B',20,24,'Reguler','2026-01-27 03:21:36','2026-01-27 03:21:52'),(193,3,'Sabtu','4',3,24,'Reguler','2026-01-27 03:21:57','2026-01-27 03:22:01'),(194,3,'Sabtu','5',3,24,'Reguler','2026-01-27 03:22:06','2026-01-27 03:22:10'),(195,3,'Sabtu','6',15,24,'Reguler','2026-01-27 03:22:21','2026-01-27 03:22:30'),(196,3,'Sabtu','C',21,24,'Reguler','2026-01-27 03:22:36','2026-01-27 03:22:44'),(197,3,'Sabtu','7',15,24,'Reguler','2026-01-27 03:22:49','2026-01-27 03:22:56'),(198,3,'Ahad','1',17,22,'Reguler','2026-01-27 03:30:42','2026-01-27 03:30:48'),(199,3,'Ahad','2',17,22,'Reguler','2026-01-27 03:31:05','2026-01-27 03:31:10'),(200,3,'Ahad','3',17,22,'Reguler','2026-01-27 03:31:15','2026-01-27 03:31:20'),(201,3,'Ahad','B',20,22,'Reguler','2026-01-27 03:31:24','2026-01-27 03:31:29'),(202,3,'Ahad','4',22,22,'Reguler','2026-01-27 03:31:34','2026-01-27 03:31:40'),(203,3,'Ahad','5',22,22,'Reguler','2026-01-27 03:31:45','2026-01-27 03:31:50'),(204,3,'Ahad','6',22,22,'Reguler','2026-01-27 03:31:54','2026-01-27 03:31:59'),(205,3,'Senin','1',4,26,'Reguler','2026-01-27 03:32:12','2026-01-27 03:32:34'),(206,3,'Senin','2',4,26,'Reguler','2026-01-27 03:32:38','2026-01-27 03:32:43'),(207,3,'Senin','3',14,26,'Reguler','2026-01-27 03:32:48','2026-01-27 03:32:54'),(208,3,'Senin','B',20,26,'Reguler','2026-01-27 03:33:38','2026-01-27 03:33:45'),(209,3,'Senin','4',9,26,'Reguler','2026-01-27 03:33:55','2026-01-27 03:34:01'),(210,3,'Senin','5',9,26,'Reguler','2026-01-27 03:34:06','2026-01-27 03:34:12'),(211,3,'Senin','6',9,26,'Reguler','2026-01-27 03:34:19','2026-01-27 03:34:24'),(212,3,'Senin','C',21,26,'Reguler','2026-01-27 03:34:28','2026-01-27 03:34:34'),(213,3,'Senin','7',9,26,'Reguler','2026-01-27 03:34:38','2026-01-27 03:34:44'),(214,3,'Selasa','1',1,22,'Reguler','2026-01-27 03:35:10','2026-01-27 03:35:15'),(215,3,'Selasa','2',1,22,'Reguler','2026-01-27 03:35:19','2026-01-27 03:35:24'),(216,3,'Selasa','3',1,22,'Reguler','2026-01-27 03:35:33','2026-01-27 03:35:38'),(217,3,'Selasa','B',20,22,'Reguler','2026-01-27 03:35:43','2026-01-27 03:35:49'),(218,3,'Selasa','4',16,22,'Reguler','2026-01-27 03:35:54','2026-01-27 03:36:03'),(219,3,'Selasa','5',16,22,'Reguler','2026-01-27 03:36:09','2026-01-27 03:36:15'),(220,3,'Selasa','6',2,22,'Reguler','2026-01-27 03:36:25','2026-01-27 03:36:33'),(221,3,'Selasa','C',21,22,'Reguler','2026-01-27 03:36:38','2026-01-27 03:36:43'),(222,3,'Selasa','7',2,22,'Reguler','2026-01-27 03:36:47','2026-01-27 03:36:53'),(223,3,'Rabu','1',8,22,'Reguler','2026-01-27 03:37:21','2026-01-27 03:37:29'),(224,3,'Rabu','2',8,22,'Reguler','2026-01-27 03:37:33','2026-01-27 03:37:38'),(225,3,'Rabu','3',1,22,'Reguler','2026-01-27 03:37:49','2026-01-27 03:37:54'),(226,3,'Rabu','B',20,22,'Reguler','2026-01-27 03:37:59','2026-01-27 03:38:04'),(227,3,'Rabu','4',1,22,'Reguler','2026-01-27 03:38:11','2026-01-27 03:38:28'),(228,3,'Rabu','5',1,22,'Reguler','2026-01-27 03:38:32','2026-01-27 03:38:38'),(229,3,'Rabu','6',1,22,'Reguler','2026-01-27 03:38:53','2026-01-27 03:38:58'),(230,3,'Rabu','C',21,22,'Reguler','2026-01-27 03:39:04','2026-01-27 03:39:10'),(231,3,'Rabu','7',1,22,'Reguler','2026-01-27 03:39:14','2026-01-27 03:39:20'),(232,3,'Kamis','1',10,22,'Reguler','2026-01-27 03:39:32','2026-01-27 03:39:38'),(233,3,'Kamis','2',10,22,'Reguler','2026-01-27 03:39:42','2026-01-27 03:39:49'),(234,3,'Kamis','3',11,22,'Reguler','2026-01-27 03:40:03','2026-01-27 03:40:11'),(235,3,'Kamis','B',20,22,'Reguler','2026-01-27 03:40:16','2026-01-27 03:40:22'),(236,3,'Kamis','4',11,22,'Reguler','2026-01-27 03:40:44','2026-01-27 03:40:53'),(237,3,'Kamis','5',6,22,'Reguler','2026-01-27 03:41:03','2026-01-27 03:41:08'),(238,3,'Kamis','6',6,22,'Reguler','2026-01-27 03:41:14','2026-01-27 03:41:19'),(239,3,'Kamis','C',21,22,'Reguler','2026-01-27 03:41:23','2026-01-27 03:41:28'),(240,3,'Kamis','7',6,22,'Reguler','2026-01-27 03:41:33','2026-01-27 03:41:43'),(241,4,'Sabtu','A',18,27,'Reguler','2026-01-27 03:43:19','2026-01-27 03:43:23'),(242,4,'Ahad','A',18,27,'Reguler','2026-01-27 03:43:32','2026-01-27 03:43:36'),(243,4,'Senin','A',19,21,'Reguler','2026-01-27 03:43:50','2026-01-27 03:43:55'),(244,4,'Selasa','A',18,27,'Reguler','2026-01-27 03:44:14','2026-01-27 03:44:18'),(245,4,'Rabu','A',18,27,'Reguler','2026-01-27 03:44:27','2026-01-27 03:44:36'),(246,4,'Kamis','A',18,27,'Reguler','2026-01-27 03:44:40','2026-01-27 03:44:44'),(247,4,'Sabtu','1',2,27,'Reguler','2026-01-27 03:45:07','2026-01-27 03:45:12'),(248,4,'Sabtu','2',2,27,'Reguler','2026-01-27 03:45:16','2026-01-27 03:45:21'),(249,4,'Sabtu','3',7,27,'Reguler','2026-01-27 03:45:29','2026-01-27 03:45:34'),(250,4,'Sabtu','B',20,27,'Reguler','2026-01-27 03:45:39','2026-01-27 03:45:45'),(251,4,'Sabtu','4',7,27,'Reguler','2026-01-27 03:45:49','2026-01-27 03:45:54'),(252,4,'Sabtu','5',7,27,'Reguler','2026-01-27 03:46:02','2026-01-27 03:46:08'),(253,4,'Sabtu','6',10,27,'Reguler','2026-01-27 03:46:26','2026-01-27 03:46:32'),(254,4,'Sabtu','C',21,27,'Reguler','2026-01-27 03:46:44','2026-01-27 03:46:50'),(255,4,'Sabtu','7',10,27,'Reguler','2026-01-27 03:46:56','2026-01-27 03:47:02'),(256,4,'Sabtu','8',11,27,'Reguler','2026-01-27 03:47:12','2026-01-27 03:47:18'),(257,4,'Sabtu','9',11,27,'Reguler','2026-01-27 03:47:22','2026-01-27 03:47:26'),(258,4,'Ahad','1',17,27,'Reguler','2026-01-27 03:48:03','2026-01-27 03:48:11'),(259,4,'Ahad','2',17,27,'Reguler','2026-01-27 03:48:17','2026-01-27 03:48:23'),(260,4,'Ahad','3',17,27,'Reguler','2026-01-27 03:48:28','2026-01-27 03:48:34'),(261,4,'Ahad','B',20,27,'Reguler','2026-01-27 03:48:40','2026-01-27 03:48:45'),(262,4,'Ahad','4',22,27,'Reguler','2026-01-27 03:48:50','2026-01-27 03:48:56'),(263,4,'Ahad','5',22,27,'Reguler','2026-01-27 03:49:00','2026-01-27 03:49:05'),(264,4,'Ahad','6',22,27,'Reguler','2026-01-27 03:49:10','2026-01-27 03:49:15'),(265,4,'Senin','1',4,21,'Reguler','2026-01-27 03:49:46','2026-01-27 03:49:52'),(266,4,'Senin','2',4,21,'Reguler','2026-01-27 03:50:54','2026-01-27 03:50:59'),(267,4,'Senin','3',13,21,'Reguler','2026-01-27 03:51:07','2026-01-27 03:51:14'),(268,4,'Senin','B',20,21,'Reguler','2026-01-27 03:51:18','2026-01-27 03:51:24'),(269,4,'Senin','4',16,21,'Reguler','2026-01-27 03:51:30','2026-01-27 03:51:42'),(270,4,'Senin','5',16,21,'Reguler','2026-01-27 03:51:50','2026-01-27 03:51:55'),(271,4,'Senin','6',16,21,'Reguler','2026-01-27 03:52:00','2026-01-27 03:52:05'),(272,4,'Senin','C',21,21,'Reguler','2026-01-27 03:52:10','2026-01-27 03:52:18'),(273,4,'Senin','7',12,21,'Reguler','2026-01-27 03:52:27','2026-01-27 03:52:37'),(274,4,'Senin','8',12,21,'Reguler','2026-01-27 03:52:44','2026-01-27 03:52:48'),(275,4,'Senin','9',12,21,'Reguler','2026-01-27 03:52:53','2026-01-27 03:52:59'),(276,4,'Selasa','1',8,27,'Reguler','2026-01-27 03:53:33','2026-01-27 03:53:38'),(277,4,'Selasa','2',8,27,'Reguler','2026-01-27 03:53:42','2026-01-27 03:53:48'),(278,4,'Selasa','3',8,27,'Reguler','2026-01-27 03:53:52','2026-01-27 03:54:03'),(279,4,'Selasa','B',20,27,'Reguler','2026-01-27 03:54:07','2026-01-27 03:54:13'),(280,4,'Selasa','4',15,27,'Reguler','2026-01-27 03:54:19','2026-01-27 03:54:31'),(281,4,'Selasa','5',15,27,'Reguler','2026-01-27 03:54:35','2026-01-27 03:54:41'),(282,4,'Selasa','6',3,27,'Reguler','2026-01-27 03:54:50','2026-01-27 03:54:54'),(283,4,'Selasa','C',21,27,'Reguler','2026-01-27 03:55:00','2026-01-27 03:55:05'),(284,4,'Selasa','7',3,27,'Reguler','2026-01-27 03:55:10','2026-01-27 03:55:21'),(285,4,'Selasa','8',5,27,'Reguler','2026-01-27 03:55:25','2026-01-27 03:55:31'),(286,4,'Selasa','9',5,27,'Reguler','2026-01-27 03:55:37','2026-01-27 03:55:41'),(287,4,'Rabu','1',9,27,'Reguler','2026-01-27 03:56:41','2026-01-27 03:56:47'),(288,4,'Rabu','2',9,27,'Reguler','2026-01-27 03:56:53','2026-01-27 03:56:59'),(289,4,'Rabu','3',9,27,'Reguler','2026-01-27 03:57:13','2026-01-27 03:57:22'),(290,4,'Rabu','B',20,27,'Reguler','2026-01-27 03:57:28','2026-01-27 03:57:33'),(291,4,'Rabu','4',9,27,'Reguler','2026-01-27 03:57:38','2026-01-27 03:57:47'),(292,4,'Rabu','5',9,27,'Reguler','2026-01-27 03:57:52','2026-01-27 03:57:57'),(293,4,'Rabu','6',1,27,'Reguler','2026-01-27 03:58:09','2026-01-27 03:58:15'),(294,4,'Rabu','C',21,27,'Reguler','2026-01-27 03:58:19','2026-01-27 03:58:24'),(295,4,'Rabu','7',1,27,'Reguler','2026-01-27 03:58:30','2026-01-27 03:58:35'),(296,4,'Rabu','8',1,27,'Reguler','2026-01-27 03:58:40','2026-01-27 03:58:45'),(297,4,'Rabu','9',1,27,'Reguler','2026-01-27 03:58:50','2026-01-27 03:58:55'),(298,4,'Kamis','1',9,27,'Reguler','2026-01-27 03:59:07','2026-01-27 03:59:14'),(299,4,'Kamis','2',9,27,'Reguler','2026-01-27 03:59:19','2026-01-27 03:59:27'),(300,4,'Kamis','3',7,27,'Reguler','2026-01-27 03:59:43','2026-01-27 03:59:49'),(301,4,'Kamis','B',20,27,'Reguler','2026-01-27 04:00:47','2026-01-27 04:00:58'),(302,4,'Kamis','4',7,27,'Reguler','2026-01-27 04:01:06','2026-01-27 04:01:12'),(303,4,'Kamis','5',7,27,'Reguler','2026-01-27 04:01:24','2026-01-27 04:01:29'),(304,4,'Kamis','6',6,27,'Reguler','2026-01-27 04:01:38','2026-01-27 04:01:44'),(305,4,'Kamis','C',21,27,'Reguler','2026-01-27 04:01:50','2026-01-27 04:01:56'),(306,4,'Kamis','7',6,27,'Reguler','2026-01-27 04:02:04','2026-01-27 04:02:09'),(307,4,'Kamis','8',1,27,'Reguler','2026-01-27 04:02:18','2026-01-27 04:02:24'),(308,4,'Kamis','9',1,27,'Reguler','2026-01-27 04:02:28','2026-01-27 04:02:34'),(309,5,'Sabtu','A',18,30,'Reguler','2026-01-27 04:02:57','2026-01-27 04:03:01'),(310,5,'Ahad','A',18,25,'Reguler','2026-01-27 04:03:16','2026-01-27 04:04:29'),(311,5,'Senin','A',19,25,'Reguler','2026-01-27 04:03:31','2026-01-27 04:03:37'),(312,5,'Selasa','A',18,18,'Reguler','2026-01-27 04:03:49','2026-01-27 04:03:52'),(313,5,'Rabu','A',18,20,'Reguler','2026-01-27 04:04:07','2026-01-27 04:04:11'),(314,5,'Kamis','A',18,18,'Reguler','2026-01-27 04:04:19','2026-01-27 04:04:22'),(315,5,'Sabtu','1',9,30,'Reguler','2026-01-27 04:05:17','2026-01-27 04:05:25'),(316,5,'Sabtu','2',9,30,'Reguler','2026-01-27 04:06:43','2026-01-27 04:06:51'),(317,5,'Sabtu','3',9,30,'Reguler','2026-01-27 04:06:55','2026-01-27 04:07:00'),(318,5,'Sabtu','B',20,30,'Reguler','2026-01-27 04:07:05','2026-01-27 04:07:10'),(319,5,'Sabtu','4',9,30,'Reguler','2026-01-27 04:07:15','2026-01-27 04:07:22'),(320,5,'Sabtu','5',9,30,'Reguler','2026-01-27 04:07:26','2026-01-27 04:07:31'),(321,5,'Sabtu','6',9,30,'Reguler','2026-01-27 04:07:36','2026-01-27 04:07:40'),(322,5,'Sabtu','C',21,30,'Reguler','2026-01-27 04:07:45','2026-01-27 04:07:50'),(323,5,'Sabtu','7',10,30,'Reguler','2026-01-27 04:07:55','2026-01-27 04:08:00'),(324,5,'Sabtu','8',10,30,'Reguler','2026-01-27 04:08:04','2026-01-27 04:08:10'),(325,5,'Sabtu','9',10,30,'Reguler','2026-01-27 04:08:14','2026-01-27 04:08:19'),(326,5,'Ahad','1',17,25,'Reguler','2026-01-27 04:10:07','2026-01-27 04:10:14'),(327,5,'Ahad','2',17,25,'Reguler','2026-01-27 04:10:19','2026-01-27 04:10:25'),(328,5,'Ahad','3',17,25,'Reguler','2026-01-27 04:10:30','2026-01-27 04:10:35'),(329,5,'Ahad','B',20,25,'Reguler','2026-01-27 04:10:40','2026-01-27 04:10:46'),(330,5,'Ahad','4',22,25,'Reguler','2026-01-27 04:10:52','2026-01-27 04:10:59'),(331,5,'Ahad','5',22,25,'Reguler','2026-01-27 04:11:05','2026-01-27 04:11:10'),(332,5,'Ahad','6',22,25,'Reguler','2026-01-27 04:11:14','2026-01-27 04:11:19'),(333,5,'Senin','1',8,25,'Reguler','2026-01-27 04:11:38','2026-01-27 04:11:44'),(334,5,'Senin','2',8,25,'Reguler','2026-01-27 04:11:50','2026-01-27 04:11:57'),(335,5,'Senin','3',1,25,'Reguler','2026-01-27 04:12:03','2026-01-27 04:12:09'),(336,5,'Senin','B',20,25,'Reguler','2026-01-27 04:12:14','2026-01-27 04:12:20'),(337,5,'Senin','4',1,25,'Reguler','2026-01-27 04:12:25','2026-01-27 04:12:31'),(338,5,'Senin','5',1,25,'Reguler','2026-01-27 04:12:37','2026-01-27 04:12:42'),(339,5,'Senin','6',1,25,'Reguler','2026-01-27 04:12:47','2026-01-27 04:12:52'),(340,5,'Senin','C',21,25,'Reguler','2026-01-27 04:12:57','2026-01-27 04:13:03'),(341,5,'Senin','7',1,25,'Reguler','2026-01-27 04:13:07','2026-01-27 04:13:12'),(342,5,'Senin','8',1,25,'Reguler','2026-01-27 04:13:17','2026-01-27 04:13:23'),(343,5,'Senin','9',1,25,'Reguler','2026-01-27 04:13:28','2026-01-27 04:13:32'),(344,5,'Selasa','1',7,18,'Reguler','2026-01-27 04:13:55','2026-01-27 04:14:00'),(345,5,'Selasa','2',7,18,'Reguler','2026-01-27 04:14:19','2026-01-27 04:14:24'),(346,5,'Selasa','3',7,18,'Reguler','2026-01-27 04:14:33','2026-01-27 04:14:38'),(347,5,'Selasa','B',20,18,'Reguler','2026-01-27 04:14:43','2026-01-27 04:14:48'),(348,5,'Selasa','4',7,18,'Reguler','2026-01-27 04:14:53','2026-01-27 04:14:58'),(349,5,'Selasa','5',7,18,'Reguler','2026-01-27 04:15:03','2026-01-27 04:15:08'),(350,5,'Selasa','6',6,18,'Reguler','2026-01-27 04:15:17','2026-01-27 04:15:22'),(351,5,'Selasa','C',21,18,'Reguler','2026-01-27 04:15:32','2026-01-27 04:15:38'),(352,5,'Selasa','7',6,18,'Reguler','2026-01-27 04:15:42','2026-01-27 04:15:47'),(353,5,'Selasa','8',11,18,'Reguler','2026-01-27 04:15:57','2026-01-27 04:16:01'),(354,5,'Selasa','9',11,18,'Reguler','2026-01-27 04:16:06','2026-01-27 04:16:10'),(355,5,'Rabu','1',4,20,'Reguler','2026-01-27 04:17:20','2026-01-27 04:17:25'),(356,5,'Rabu','2',4,20,'Reguler','2026-01-27 04:17:29','2026-01-27 04:17:34'),(357,5,'Rabu','3',4,20,'Reguler','2026-01-27 04:17:40','2026-01-27 04:17:50'),(358,5,'Rabu','B',20,20,'Reguler','2026-01-27 04:17:55','2026-01-27 04:18:01'),(359,5,'Rabu','4',13,20,'Reguler','2026-01-27 04:18:06','2026-01-27 04:18:20'),(360,5,'Rabu','5',13,20,'Reguler','2026-01-27 04:18:24','2026-01-27 04:18:30'),(361,5,'Rabu','6',12,20,'Reguler','2026-01-27 04:18:35','2026-01-27 04:18:43'),(362,5,'Rabu','C',21,20,'Reguler','2026-01-27 04:18:49','2026-01-27 04:18:57'),(363,5,'Rabu','7',12,20,'Reguler','2026-01-27 04:19:03','2026-01-27 04:19:09'),(364,5,'Rabu','8',15,20,'Reguler','2026-01-27 04:19:13','2026-01-27 04:19:20'),(365,5,'Rabu','9',15,20,'Reguler','2026-01-27 04:19:24','2026-01-27 04:19:29'),(366,5,'Kamis','1',2,18,'Reguler','2026-01-27 04:19:46','2026-01-27 04:19:51'),(367,5,'Kamis','2',2,18,'Reguler','2026-01-27 04:19:58','2026-01-27 04:20:03'),(368,5,'Kamis','3',2,18,'Reguler','2026-01-27 04:20:08','2026-01-27 04:20:15'),(369,5,'Kamis','B',20,18,'Reguler','2026-01-27 04:20:20','2026-01-27 04:20:26'),(370,5,'Kamis','4',3,18,'Reguler','2026-01-27 04:20:32','2026-01-27 04:20:40'),(371,5,'Kamis','5',3,18,'Reguler','2026-01-27 04:20:45','2026-01-27 04:20:49'),(372,5,'Kamis','6',16,18,'Reguler','2026-01-27 04:20:58','2026-01-27 04:21:04'),(373,5,'Kamis','C',21,18,'Reguler','2026-01-27 04:21:08','2026-01-27 04:21:14'),(374,5,'Kamis','7',16,18,'Reguler','2026-01-27 04:21:18','2026-01-27 04:21:27'),(375,5,'Kamis','8',5,18,'Reguler','2026-01-27 04:21:34','2026-01-27 04:21:39'),(376,5,'Kamis','9',5,18,'Reguler','2026-01-27 04:21:43','2026-01-27 04:21:48'),(377,6,'Sabtu','A',18,19,'Ramadhan','2026-01-27 04:50:48','2026-01-27 04:50:53'),(378,6,'Sabtu','1',1,19,'Ramadhan','2026-01-27 04:51:00','2026-01-27 04:51:25'),(379,6,'Sabtu','2',1,19,'Ramadhan','2026-01-27 04:51:30','2026-01-27 04:51:34'),(380,6,'Sabtu','3',1,19,'Ramadhan','2026-01-27 04:51:39','2026-01-27 04:51:44'),(381,6,'Sabtu','4',1,19,'Ramadhan','2026-01-27 04:51:48','2026-01-27 04:51:55'),(382,6,'Sabtu','B',20,19,'Ramadhan','2026-01-27 04:52:03','2026-01-27 04:52:10'),(383,6,'Sabtu','5',3,19,'Ramadhan','2026-01-27 04:54:08','2026-01-27 04:54:15'),(384,6,'Sabtu','6',3,19,'Ramadhan','2026-01-27 04:54:21','2026-01-27 04:54:25'),(385,6,'Sabtu','7',2,19,'Ramadhan','2026-01-27 04:54:29','2026-01-27 04:54:37'),(386,6,'Sabtu','8',2,19,'Ramadhan','2026-01-27 04:54:41','2026-01-27 04:54:45'),(387,6,'Ahad','A',18,19,'Ramadhan','2026-01-27 04:58:07','2026-01-27 06:17:33'),(388,6,'Senin','A',18,19,'Ramadhan','2026-01-27 06:17:47','2026-01-27 06:17:54'),(389,6,'Selasa','A',18,19,'Ramadhan','2026-01-27 06:18:10','2026-01-27 06:18:14'),(390,6,'Rabu','A',18,20,'Ramadhan','2026-01-27 06:18:40','2026-01-27 06:18:43'),(391,6,'Kamis','A',18,30,'Ramadhan','2026-01-27 06:18:55','2026-01-27 06:18:58');
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
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_jam_mengajar`
--

LOCK TABLES `tb_jam_mengajar` WRITE;
/*!40000 ALTER TABLE `tb_jam_mengajar` DISABLE KEYS */;
INSERT INTO `tb_jam_mengajar` VALUES (1,'1','07:10:00','07:45:00','Reguler'),(2,'2','07:45:00','08:20:00','Reguler'),(3,'3','08:20:00','08:55:00','Reguler'),(4,'4','09:20:00','09:55:00','Reguler'),(5,'5','09:55:00','10:30:00','Reguler'),(6,'6','10:30:00','11:05:00','Reguler'),(7,'7','11:15:00','11:50:00','Reguler'),(8,'8','11:50:00','12:15:00','Reguler'),(9,'9','12:15:00','12:35:00','Reguler'),(11,'A','07:30:00','07:45:00','Ramadhan'),(12,'A','06:50:00','07:10:00','Reguler'),(13,'B','08:55:00','09:20:00','Reguler'),(14,'C','11:05:00','11:15:00','Reguler'),(16,'B','09:10:00','09:40:00','Ramadhan'),(17,'1','07:45:00','08:10:00','Ramadhan'),(18,'2','08:10:00','08:30:00','Ramadhan'),(19,'3','08:30:00','08:50:00','Ramadhan'),(20,'4','08:50:00','09:10:00','Ramadhan'),(21,'5','09:40:00','10:00:00','Ramadhan'),(22,'6','10:00:00','10:20:00','Ramadhan'),(23,'7','10:20:00','10:40:00','Ramadhan'),(24,'8','10:40:00','11:00:00','Ramadhan');
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
  `jenis` enum('Reguler','Ramadhan') DEFAULT 'Reguler',
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
INSERT INTO `tb_jurnal` VALUES (3,1,24,'1,2','Reguler','Al-Quran Hadis','Surat Al-Lahab','2026-01-22','2026-01-22 12:23:45'),(4,5,20,'1,2,3','Reguler','Al-Quran Hadis','Surat An-Naas','2026-01-22','2026-01-22 12:59:30'),(7,6,19,'1,2,3','Reguler','IPAS','Tata surya dan planet-planet','2026-01-23','2026-01-22 21:25:58'),(8,6,19,'1,2,3','Reguler','IPAS','Tata surya dan planet-planet','2026-01-23','2026-01-22 21:26:14'),(9,6,19,'4,5','Reguler','Bahasa Arab','Fiil Madli','2026-01-23','2026-01-22 22:39:57'),(10,2,24,'1,2','Reguler','Akidah Akhlak','Akhlak terpuji','2026-01-23','2026-01-22 23:00:01'),(11,5,20,'1,2','Reguler','Al-Quran Hadis','Hadis Tentang Shodakoh','2026-01-23','2026-01-22 23:08:45'),(12,6,19,'1,2,3','Reguler','Bahasa Indonesia','Materi Persiapan TKA 2026','2026-01-24','2026-01-24 12:41:46'),(13,6,19,'1,2,3','Reguler','Pendidikan Pancasila','Mengerjakan Uji Kompetensi Bab 1','2026-01-26','2026-01-26 00:21:04');
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
-- Table structure for table `tb_log`
--

DROP TABLE IF EXISTS `tb_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_log` (
  `id_log` int NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL,
  `activity` varchar(100) NOT NULL,
  `details` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_log`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_log`
--

LOCK TABLES `tb_log` WRITE;
/*!40000 ALTER TABLE `tb_log` DISABLE KEYS */;
INSERT INTO `tb_log` VALUES (1,'system','Logout','User logged out from Unknown session','2026-01-29 10:29:26'),(2,'admin','Login','User logged in successfully','2026-01-29 10:29:36'),(3,'admin','Update Guru','Memperbarui data guru: Nur Huda, S.Pd.I.','2026-01-29 10:41:30'),(4,'admin','Logout','User logged out from admin session','2026-01-29 10:43:55'),(5,'3184602457','Login','Student logged in successfully using NISN','2026-01-29 10:44:10'),(6,'3184602457','Logout','User logged out from siswa session','2026-01-29 10:44:13'),(7,'admin','Login','User logged in successfully','2026-01-29 10:44:15'),(8,'admin','Logout','User logged out from admin session','2026-01-29 10:51:04'),(9,'3146588936','Login','Student logged in successfully using NISN','2026-01-29 10:51:10'),(10,'3146588936','Logout','User logged out from siswa session','2026-01-29 11:09:15'),(11,'admin','Login','User logged in successfully','2026-01-29 11:09:19'),(12,'admin','Logout','User logged out from admin session','2026-01-29 11:09:56'),(13,'3146588936','Login','Student logged in successfully using NISN','2026-01-29 11:09:59'),(14,'3146588936','Logout','User logged out from siswa session','2026-01-29 11:10:05'),(15,'admin','Login','User logged in successfully','2026-01-29 11:10:08');
/*!40000 ALTER TABLE `tb_log` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_mata_pelajaran`
--

DROP TABLE IF EXISTS `tb_mata_pelajaran`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_mata_pelajaran` (
  `id_mapel` int NOT NULL AUTO_INCREMENT,
  `kode_mapel` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `nama_mapel` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kktp` int DEFAULT NULL,
  PRIMARY KEY (`id_mapel`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_mata_pelajaran`
--

LOCK TABLES `tb_mata_pelajaran` WRITE;
/*!40000 ALTER TABLE `tb_mata_pelajaran` DISABLE KEYS */;
INSERT INTO `tb_mata_pelajaran` VALUES (1,'7','Bahasa Indonesia',70),(2,'5','Bahasa Arab',70),(3,'2','Akidah Akhlak',70),(4,'1','Al-Quran Hadis',70),(5,'4','Sejarah Kebudayaan Islam',70),(6,'6','Pendidikan Pancasila',70),(7,'20','IPAS',70),(8,'11','PJOK',70),(9,'10','Matematika',70),(10,'14','Bahasa Inggris',70),(11,'12','Seni Budaya',70),(12,'16','Ke-NU-an',70),(13,'17','Tajwid',70),(14,'15','BTA',70),(15,'13','Bahasa Jawa',70),(16,'3','Fikih',70),(17,'19','Kepramukaan',70),(18,'A','Asmaul Husna dan Tadarrus',NULL),(19,'B','Upacara Bendera',NULL),(20,'C','Istirahat I',NULL),(21,'D','Istirahat II',NULL),(22,'18','Ekstrakurikuler',NULL);
/*!40000 ALTER TABLE `tb_mata_pelajaran` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_nilai_harian_detail`
--

DROP TABLE IF EXISTS `tb_nilai_harian_detail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_nilai_harian_detail` (
  `id_detail` int NOT NULL AUTO_INCREMENT,
  `id_header` int NOT NULL,
  `id_siswa` int NOT NULL,
  `nilai` int DEFAULT '0',
  `nilai_jadi` int DEFAULT NULL,
  PRIMARY KEY (`id_detail`),
  UNIQUE KEY `idx_header_siswa` (`id_header`,`id_siswa`),
  CONSTRAINT `tb_nilai_harian_detail_ibfk_1` FOREIGN KEY (`id_header`) REFERENCES `tb_nilai_harian_header` (`id_header`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=191 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_nilai_harian_detail`
--

LOCK TABLES `tb_nilai_harian_detail` WRITE;
/*!40000 ALTER TABLE `tb_nilai_harian_detail` DISABLE KEYS */;
INSERT INTO `tb_nilai_harian_detail` VALUES (1,1,115,70,NULL),(2,1,116,80,NULL),(3,1,117,90,NULL),(4,1,118,89,NULL),(5,1,119,89,NULL),(6,1,120,78,NULL),(7,1,121,95,NULL),(8,1,122,98,NULL),(9,1,123,78,NULL),(10,1,124,76,NULL),(11,1,125,65,NULL),(12,1,126,73,NULL),(13,1,127,65,NULL),(14,1,128,78,NULL),(15,1,129,78,NULL),(16,1,130,72,NULL),(17,1,131,72,NULL),(18,1,132,75,NULL),(19,1,133,76,NULL),(20,2,115,67,NULL),(21,2,116,78,NULL),(22,2,117,77,NULL),(23,2,118,78,NULL),(24,2,119,65,NULL),(25,2,120,64,NULL),(26,2,121,67,NULL),(27,2,122,78,NULL),(28,2,123,83,NULL),(29,2,124,64,NULL),(30,2,125,63,NULL),(31,2,126,67,NULL),(32,2,127,68,NULL),(33,2,128,87,NULL),(34,2,129,77,NULL),(35,2,130,74,NULL),(36,2,131,76,NULL),(37,2,132,78,NULL),(38,2,133,67,NULL),(39,3,115,78,84),(40,3,116,78,84),(41,3,117,74,77),(42,3,118,76,81),(43,3,119,89,96),(44,3,120,65,70),(45,3,121,76,81),(46,3,122,67,70),(47,3,123,78,84),(48,3,124,56,70),(49,3,125,67,70),(50,3,126,78,84),(51,3,127,62,70),(52,3,128,56,70),(53,3,129,78,84),(54,3,130,78,84),(55,3,131,77,82),(56,3,132,71,72),(57,3,133,72,74),(172,6,115,NULL,NULL),(173,6,116,NULL,NULL),(174,6,117,NULL,NULL),(175,6,118,NULL,NULL),(176,6,119,NULL,NULL),(177,6,120,NULL,NULL),(178,6,121,NULL,NULL),(179,6,122,NULL,NULL),(180,6,123,NULL,NULL),(181,6,124,NULL,NULL),(182,6,125,NULL,NULL),(183,6,126,NULL,NULL),(184,6,127,NULL,NULL),(185,6,128,NULL,NULL),(186,6,129,NULL,NULL),(187,6,130,NULL,NULL),(188,6,131,NULL,NULL),(189,6,132,NULL,NULL),(190,6,133,NULL,NULL);
/*!40000 ALTER TABLE `tb_nilai_harian_detail` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_nilai_harian_header`
--

DROP TABLE IF EXISTS `tb_nilai_harian_header`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_nilai_harian_header` (
  `id_header` int NOT NULL AUTO_INCREMENT,
  `id_guru` int NOT NULL,
  `id_kelas` int NOT NULL,
  `id_mapel` int NOT NULL,
  `nama_penilaian` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `materi` text COLLATE utf8mb4_unicode_ci,
  `tahun_ajaran` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `semester` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_header`),
  KEY `idx_guru_kelas` (`id_guru`,`id_kelas`),
  KEY `id_mapel` (`id_mapel`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_nilai_harian_header`
--

LOCK TABLES `tb_nilai_harian_header` WRITE;
/*!40000 ALTER TABLE `tb_nilai_harian_header` DISABLE KEYS */;
INSERT INTO `tb_nilai_harian_header` VALUES (1,19,6,0,'H1',NULL,'2025/2026','Semester 2','2026-01-30 13:27:44'),(2,19,6,0,'H2',NULL,'2025/2026','Semester 2','2026-01-30 13:28:51'),(3,19,6,3,'H1','Kalimat Tahlil','2025/2026','Semester 2','2026-01-30 13:51:21'),(4,19,6,2,'H1',NULL,'2025/2026','Semester 2','2026-01-30 13:52:22'),(5,19,6,2,'H2',NULL,'2025/2026','Semester 2','2026-01-30 13:52:26'),(6,19,6,3,'H2','Asmaul Husna','2025/2026','Semester 2','2026-01-30 13:52:32'),(7,19,6,11,'H1',NULL,'2025/2026','Semester 2','2026-01-30 14:01:10'),(8,19,6,11,'H2',NULL,'2025/2026','Semester 2','2026-01-30 14:01:13'),(9,19,6,3,'H3',NULL,'2025/2026','Semester 2','2026-01-31 00:03:01');
/*!40000 ALTER TABLE `tb_nilai_harian_header` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_nilai_kokurikuler_detail`
--

DROP TABLE IF EXISTS `tb_nilai_kokurikuler_detail`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_nilai_kokurikuler_detail` (
  `id_detail` int NOT NULL AUTO_INCREMENT,
  `id_header` int NOT NULL,
  `id_siswa` int NOT NULL,
  `nilai` float DEFAULT '0',
  `nilai_jadi` float DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_detail`),
  KEY `id_header` (`id_header`),
  KEY `id_siswa` (`id_siswa`),
  CONSTRAINT `tb_nilai_kokurikuler_detail_ibfk_1` FOREIGN KEY (`id_header`) REFERENCES `tb_nilai_kokurikuler_header` (`id_header`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_nilai_kokurikuler_detail`
--

LOCK TABLES `tb_nilai_kokurikuler_detail` WRITE;
/*!40000 ALTER TABLE `tb_nilai_kokurikuler_detail` DISABLE KEYS */;
INSERT INTO `tb_nilai_kokurikuler_detail` VALUES (1,1,115,67,70,'2026-01-31 10:24:22','2026-01-31 10:27:28'),(2,1,116,78,84,'2026-01-31 10:24:22','2026-01-31 10:27:42'),(3,1,117,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(4,1,118,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(5,1,119,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(6,1,120,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(7,1,121,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(8,1,122,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(9,1,123,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(10,1,124,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(11,1,125,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(12,1,126,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(13,1,127,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(14,1,128,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(15,1,129,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(16,1,130,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(17,1,131,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(18,1,132,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(19,1,133,0,0,'2026-01-31 10:24:22','2026-01-31 10:24:22'),(20,2,115,78,84,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(21,2,116,67,70,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(22,2,117,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(23,2,118,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(24,2,119,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(25,2,120,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(26,2,121,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(27,2,122,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(28,2,123,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(29,2,124,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(30,2,125,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(31,2,126,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(32,2,127,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(33,2,128,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(34,2,129,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(35,2,130,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(36,2,131,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(37,2,132,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17'),(38,2,133,0,0,'2026-01-31 10:29:17','2026-01-31 10:29:17');
/*!40000 ALTER TABLE `tb_nilai_kokurikuler_detail` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_nilai_kokurikuler_header`
--

DROP TABLE IF EXISTS `tb_nilai_kokurikuler_header`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_nilai_kokurikuler_header` (
  `id_header` int NOT NULL AUTO_INCREMENT,
  `id_guru` int NOT NULL,
  `id_kelas` int NOT NULL,
  `id_mapel` int NOT NULL,
  `nama_penilaian` varchar(50) NOT NULL,
  `jenis_kegiatan` text,
  `tgl_kegiatan` date DEFAULT NULL,
  `tahun_ajaran` varchar(20) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_header`),
  KEY `id_guru` (`id_guru`),
  KEY `id_kelas` (`id_kelas`),
  KEY `id_mapel` (`id_mapel`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_nilai_kokurikuler_header`
--

LOCK TABLES `tb_nilai_kokurikuler_header` WRITE;
/*!40000 ALTER TABLE `tb_nilai_kokurikuler_header` DISABLE KEYS */;
INSERT INTO `tb_nilai_kokurikuler_header` VALUES (1,19,6,3,'K1','Klasifikasi Sampah','2026-01-22','2025/2026','Semester 2','2026-01-31 10:24:01'),(2,19,6,3,'K2','Peduli Lingkungan','2026-01-14','2025/2026','Semester 2','2026-01-31 10:28:56');
/*!40000 ALTER TABLE `tb_nilai_kokurikuler_header` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_nilai_semester`
--

DROP TABLE IF EXISTS `tb_nilai_semester`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_nilai_semester` (
  `id_nilai` int NOT NULL AUTO_INCREMENT,
  `id_siswa` int NOT NULL,
  `id_mapel` int NOT NULL,
  `id_kelas` int NOT NULL,
  `id_guru` int NOT NULL,
  `jenis_semester` enum('UTS','UAS','PAT','Pra Ujian','Ujian') NOT NULL,
  `tahun_ajaran` varchar(20) NOT NULL,
  `semester` varchar(20) NOT NULL,
  `nilai_asli` decimal(5,2) DEFAULT '0.00',
  `nilai_remidi` decimal(5,2) DEFAULT '0.00',
  `nilai_jadi` decimal(5,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_nilai`),
  UNIQUE KEY `unique_nilai` (`id_siswa`,`id_mapel`,`jenis_semester`,`tahun_ajaran`,`semester`),
  KEY `id_mapel` (`id_mapel`),
  KEY `id_kelas` (`id_kelas`),
  KEY `id_guru` (`id_guru`),
  CONSTRAINT `tb_nilai_semester_ibfk_1` FOREIGN KEY (`id_siswa`) REFERENCES `tb_siswa` (`id_siswa`) ON DELETE CASCADE,
  CONSTRAINT `tb_nilai_semester_ibfk_2` FOREIGN KEY (`id_mapel`) REFERENCES `tb_mata_pelajaran` (`id_mapel`) ON DELETE CASCADE,
  CONSTRAINT `tb_nilai_semester_ibfk_3` FOREIGN KEY (`id_kelas`) REFERENCES `tb_kelas` (`id_kelas`) ON DELETE CASCADE,
  CONSTRAINT `tb_nilai_semester_ibfk_4` FOREIGN KEY (`id_guru`) REFERENCES `tb_guru` (`id_guru`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_nilai_semester`
--

LOCK TABLES `tb_nilai_semester` WRITE;
/*!40000 ALTER TABLE `tb_nilai_semester` DISABLE KEYS */;
INSERT INTO `tb_nilai_semester` VALUES (1,115,3,6,19,'UTS','2025/2026','Semester 2',50.00,60.00,70.00,'2026-01-31 02:00:58','2026-01-31 02:12:27'),(2,116,3,6,19,'UTS','2025/2026','Semester 2',76.00,0.00,81.00,'2026-01-31 02:16:06','2026-01-31 02:16:06'),(3,117,3,6,19,'UTS','2025/2026','Semester 2',80.00,0.00,87.00,'2026-01-31 02:16:29','2026-01-31 02:16:29'),(4,118,3,6,19,'UTS','2025/2026','Semester 2',45.00,60.00,70.00,'2026-01-31 02:21:24','2026-01-31 02:21:24'),(5,119,3,6,19,'UTS','2025/2026','Semester 2',56.00,67.00,70.00,'2026-01-31 02:27:56','2026-01-31 02:27:56'),(6,120,3,6,19,'UTS','2025/2026','Semester 2',34.00,65.00,70.00,'2026-01-31 02:29:25','2026-01-31 02:29:25'),(7,115,3,6,19,'UAS','2025/2026','Semester 2',67.00,78.00,84.00,'2026-01-31 02:39:18','2026-01-31 02:39:18'),(8,116,3,6,19,'UAS','2025/2026','Semester 2',87.00,0.00,94.00,'2026-01-31 03:37:43','2026-01-31 03:37:43'),(9,115,3,6,19,'PAT','2025/2026','Semester 2',67.00,77.00,82.00,'2026-01-31 03:38:10','2026-01-31 03:38:10'),(10,118,3,6,19,'PAT','2025/2026','Semester 2',78.00,87.00,94.00,'2026-01-31 03:38:20','2026-01-31 03:38:20'),(11,115,3,6,19,'Pra Ujian','2025/2026','Semester 2',45.00,67.00,70.00,'2026-01-31 03:38:34','2026-01-31 03:38:34'),(12,116,3,6,19,'Pra Ujian','2025/2026','Semester 2',56.00,65.00,70.00,'2026-01-31 03:38:43','2026-01-31 03:38:43'),(13,115,3,6,19,'Ujian','2025/2026','Semester 2',78.00,89.00,96.00,'2026-01-31 03:38:57','2026-01-31 03:38:57'),(14,116,3,6,19,'Ujian','2025/2026','Semester 2',45.00,65.00,70.00,'2026-01-31 03:39:03','2026-01-31 03:39:03');
/*!40000 ALTER TABLE `tb_nilai_semester` ENABLE KEYS */;
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
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_notifikasi`
--

LOCK TABLES `tb_notifikasi` WRITE;
/*!40000 ALTER TABLE `tb_notifikasi` DISABLE KEYS */;
INSERT INTO `tb_notifikasi` VALUES (23,'Nur Huda, S.Pd.I. telah melakukan input absensi siswa VI (19 siswa)','rekap_absensi.php',1,'2026-01-31 06:46:22'),(24,'Ali Yasin, S.Pd.I (Wali) telah mengirim kehadiran pada pukul 13:47 tanggal 31-01-2026','absensi_guru.php',1,'2026-01-31 06:47:18'),(25,'Ali Yasin, S.Pd.I telah melakukan input absensi siswa III (20 siswa)','rekap_absensi.php',1,'2026-01-31 06:47:39');
/*!40000 ALTER TABLE `tb_notifikasi` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_password_resets`
--

DROP TABLE IF EXISTS `tb_password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `email` (`email`),
  KEY `token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_password_resets`
--

LOCK TABLES `tb_password_resets` WRITE;
/*!40000 ALTER TABLE `tb_password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `tb_password_resets` ENABLE KEYS */;
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
  `email` varchar(100) DEFAULT NULL,
  `nama` varchar(100) NOT NULL DEFAULT '',
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
INSERT INTO `tb_pengguna` VALUES (1,'admin',NULL,'Nur Huda, S.Pd.I.','$2y$10$AGvfJEpOAtAWYnws4pFgRutCpx3cjBB7tT5OzabhkHgR.HceF7ZIq','admin',NULL,'user_1769126130_LOGO.png'),(2,'kepala',NULL,'Musriah, S.Pd.I.','$2y$10$wKqi4ukTK96K3pUDi5/tq..vwpSS6JsLXOOD28zYfbY3Ceginvd.e','kepala_madrasah',NULL,NULL),(3,'tu',NULL,'Khoiruddin, S.Pd.','$2y$10$wHZGfb.iEFeJzSQ3EAZYYOGbVXVKheuH4.ihYVIg2Du7m20m5uP/W','tata_usaha',NULL,NULL);
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
  `tempat_jadwal` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_profil_madrasah`
--

LOCK TABLES `tb_profil_madrasah` WRITE;
/*!40000 ALTER TABLE `tb_profil_madrasah` DISABLE KEYS */;
INSERT INTO `tb_profil_madrasah` VALUES (1,'YAYASAN SULTAN FATTAH JEPARA','MI Sultan Fattah Sukosono','Musriah, S.Pd.I.','2025/2026','Semester 2','logo_1768301957.png','hero_1769291816.jpg','2025-07-14','Sukosono');
/*!40000 ALTER TABLE `tb_profil_madrasah` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_sholat`
--

DROP TABLE IF EXISTS `tb_sholat`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_sholat` (
  `id_sholat` int NOT NULL AUTO_INCREMENT,
  `id_siswa` int NOT NULL,
  `tanggal` date NOT NULL,
  `status` enum('Hadir','Tidak Hadir','Berhalangan') NOT NULL DEFAULT 'Tidak Hadir',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_sholat`),
  KEY `id_siswa` (`id_siswa`),
  CONSTRAINT `tb_sholat_ibfk_1` FOREIGN KEY (`id_siswa`) REFERENCES `tb_siswa` (`id_siswa`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_sholat`
--

LOCK TABLES `tb_sholat` WRITE;
/*!40000 ALTER TABLE `tb_sholat` DISABLE KEYS */;
INSERT INTO `tb_sholat` VALUES (1,115,'2026-01-29','Berhalangan','2026-01-29 13:02:46','2026-01-29 13:02:46'),(2,116,'2026-01-29','Hadir','2026-01-29 14:23:03','2026-01-29 14:23:03'),(3,117,'2026-01-29','Hadir','2026-01-29 14:23:03','2026-01-29 14:23:03'),(4,118,'2026-01-29','Hadir','2026-01-29 14:23:03','2026-01-29 14:23:03'),(5,119,'2026-01-29','Hadir','2026-01-29 14:23:03','2026-01-29 14:23:03'),(6,120,'2026-01-29','Hadir','2026-01-29 14:23:03','2026-01-29 14:23:03'),(7,121,'2026-01-29','Hadir','2026-01-29 14:23:03','2026-01-29 14:23:03'),(8,122,'2026-01-29','Hadir','2026-01-29 14:23:03','2026-01-29 14:23:03'),(9,123,'2026-01-29','Hadir','2026-01-29 14:23:04','2026-01-29 14:23:04'),(10,124,'2026-01-29','Hadir','2026-01-29 14:23:04','2026-01-29 14:23:04'),(11,125,'2026-01-29','Hadir','2026-01-29 14:23:04','2026-01-29 14:23:04'),(12,126,'2026-01-29','Hadir','2026-01-29 14:23:04','2026-01-29 14:23:04'),(13,127,'2026-01-29','Hadir','2026-01-29 14:23:04','2026-01-29 14:23:04'),(14,128,'2026-01-29','Hadir','2026-01-29 14:23:04','2026-01-29 14:23:04'),(15,129,'2026-01-29','Hadir','2026-01-29 14:23:04','2026-01-29 14:23:04'),(16,130,'2026-01-29','Hadir','2026-01-29 14:23:04','2026-01-29 14:23:04'),(17,131,'2026-01-29','Hadir','2026-01-29 14:23:04','2026-01-29 14:23:04'),(18,132,'2026-01-29','Hadir','2026-01-29 14:23:04','2026-01-29 14:23:04'),(19,133,'2026-01-29','Hadir','2026-01-29 14:23:04','2026-01-29 14:23:04');
/*!40000 ALTER TABLE `tb_sholat` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_sholat_dhuha`
--

DROP TABLE IF EXISTS `tb_sholat_dhuha`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_sholat_dhuha` (
  `id_sholat` int NOT NULL AUTO_INCREMENT,
  `id_siswa` int NOT NULL,
  `tanggal` date NOT NULL,
  `status` enum('Hadir','Tidak Hadir','Berhalangan') NOT NULL DEFAULT 'Tidak Hadir',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_sholat`),
  KEY `id_siswa` (`id_siswa`),
  KEY `tanggal` (`tanggal`),
  CONSTRAINT `fk_sholat_dhuha_siswa` FOREIGN KEY (`id_siswa`) REFERENCES `tb_siswa` (`id_siswa`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_sholat_dhuha`
--

LOCK TABLES `tb_sholat_dhuha` WRITE;
/*!40000 ALTER TABLE `tb_sholat_dhuha` DISABLE KEYS */;
INSERT INTO `tb_sholat_dhuha` VALUES (1,115,'2026-01-29','Berhalangan','2026-01-29 14:22:44','2026-01-29 14:22:44'),(2,116,'2026-01-29','Hadir','2026-01-29 14:22:44','2026-01-29 14:22:44'),(3,117,'2026-01-29','Hadir','2026-01-29 14:22:44','2026-01-29 14:22:44'),(4,118,'2026-01-29','Hadir','2026-01-29 14:22:45','2026-01-29 14:22:45'),(5,119,'2026-01-29','Hadir','2026-01-29 14:22:45','2026-01-29 14:22:45'),(6,120,'2026-01-29','Hadir','2026-01-29 14:22:45','2026-01-29 14:22:45'),(7,121,'2026-01-29','Hadir','2026-01-29 14:22:45','2026-01-29 14:22:45'),(8,122,'2026-01-29','Hadir','2026-01-29 14:22:45','2026-01-29 14:22:45'),(9,123,'2026-01-29','Hadir','2026-01-29 14:22:45','2026-01-29 14:22:45'),(10,124,'2026-01-29','Hadir','2026-01-29 14:22:45','2026-01-29 14:22:45'),(11,125,'2026-01-29','Hadir','2026-01-29 14:22:45','2026-01-29 14:22:45'),(12,126,'2026-01-29','Hadir','2026-01-29 14:22:45','2026-01-29 14:22:45'),(13,127,'2026-01-29','Hadir','2026-01-29 14:22:45','2026-01-29 14:22:45'),(14,128,'2026-01-29','Hadir','2026-01-29 14:22:45','2026-01-29 14:22:45'),(15,129,'2026-01-29','Hadir','2026-01-29 14:22:46','2026-01-29 14:22:46'),(16,130,'2026-01-29','Hadir','2026-01-29 14:22:46','2026-01-29 14:22:46'),(17,131,'2026-01-29','Hadir','2026-01-29 14:22:46','2026-01-29 14:22:46'),(18,132,'2026-01-29','Hadir','2026-01-29 14:22:46','2026-01-29 14:22:46'),(19,133,'2026-01-29','Hadir','2026-01-29 14:22:46','2026-01-29 14:22:46');
/*!40000 ALTER TABLE `tb_sholat_dhuha` ENABLE KEYS */;
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
  `email` varchar(100) DEFAULT NULL,
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
INSERT INTO `tb_siswa` VALUES (22,'ABDULLAH HASAN',NULL,'3184602457','L',1,'$2y$10$LmIhsicDZbNcuG6olyGkIO2C8ahVGgEx4AHLQQJZhHKyHGiiiuuhq'),(23,'ABIZAR HABIBILLAH',NULL,'3184275775','L',1,'$2y$10$g2hTsLpRKbNvFsGkO9upwukGnJ2We6aeAvDiq.gFyzk4od3RXLrBa'),(24,'ADHITAMA ELVAN SYAHREZA',NULL,'3180229036','L',1,'$2y$10$2L6RpsdmgspXSsYALOsv/OuHdQWFP3VSQaaks9eQzmRhkf6/daTim'),(25,'AHMAD MANUTHO MUHAMMAD',NULL,'3182663303','L',1,'$2y$10$Lt.wvuZBSNAdipc0wFaJ.eheVUM9ypKie3cXGPqCClTcTgq52anhi'),(26,'AIRA ZAHWA SAFIRA',NULL,'3194980092','P',1,'$2y$10$PA8TqMgMxbBaEEiFOnK/aOm/a/5rbakklsxjyu7WMh1xnZ2Is9H36'),(27,'ARFAN MIYAZ ALINDRA',NULL,'3182355082','L',1,'$2y$10$UY7Pb/npFYj.vrWTLFATaO9G4XAfRawutxA3H9p5D6ikYLUYZHU.W'),(28,'DELISA ALYA SAFIQNA',NULL,'3195153075','P',1,'$2y$10$ogCQovvwZdhD4uwUkME0VOosqzKaIydLZbVzB3Z6PhInZmnAr48Gi'),(29,'DIAN AIRA',NULL,'3195813730','P',1,'$2y$10$3wZH0oP6Rrq81YhZEVWpcO/cOR6n7.okHrfopWkrrVnPrjBE.7cXm'),(30,'DHIRA QALESYA',NULL,'3184245017','P',1,'$2y$10$plqI0PgGXAbMor5DErKvGetEJL92GgK0cgI/EhkvXGqmVOsaVqa4W'),(31,'HIBAT ALMALIK',NULL,'3183882033','L',1,'$2y$10$Ko2FfsB1ye45jTH5Joeg.eg315RTCPuSejDTAdIqKsR3ABx4MgKda'),(32,'JIHAN FADHILLAH',NULL,'3194274202','P',1,'$2y$10$YPMPBTYqM.FHgYqt2gllvuzEcU8XrqbDDYzrXYE3aBjCI39E9Ba..'),(33,'KAYLA PUTRI AMALIA',NULL,'3177681680','P',1,'$2y$10$K30WPyMNBKyTRU05vsWFQe8Mw8fXNzyCVVXcrQB6RW46g01h6h1/a'),(34,'LAILATUL JANNATU AZZA',NULL,'3190992049','P',1,'$2y$10$6KggFknKafZ0ROK65dV83u85U4pm.aRO20evQLh/1hwBdXWzSPT/6'),(35,'MAUWAFIQ KHOIRUL FAJAR',NULL,'3172404776','L',1,'$2y$10$/7A0geAlcuqxndtyuqTTB.o.bxx9lV/3zmxDvHVn5bGhgWcV9EofW'),(36,'NORREIN NABIHA',NULL,'3198116081','P',1,'$2y$10$m64PLAbB2BdjU0fJDjRO.OkyHwOpi9wBK/CWj.VYWhKC3ZuFVQgwK'),(37,'RHEVA PUTRI RAMADHANI',NULL,'3186829907','P',1,'$2y$10$Lzil3MynYlkpQJGRd/MLpOkjZubtYivlE4oxFFFZckhtNtCWev9fq'),(38,'SALMA SHAFIRA RAYYANA',NULL,'3188013385','P',1,'$2y$10$S0OAmCbn5qfzSD1UoKz4YOwLVv99bKvoGoV2/u5kA1nwY7y7.rnn.'),(39,'TUSAMMA SALSABILA',NULL,'3184514039','P',1,'$2y$10$RziHqMaTSBlJnT966KMzHe1EIjSP6zZpjaXXfK3LMuukFqqvtgE8C'),(40,'ABIZARD ALTAN MUTTAQI',NULL,'3179401623','L',2,'$2y$10$a0O0w/fyyFAmsjJXey2nfO/uC.IS.ksG33IhQzPt6EkY9IEkE.3d6'),(41,'AFWAN SETYO MANGGALA PUTRA',NULL,'3174187068','L',2,'$2y$10$EgRinF.w4mRuRbj1hHRyM.UeEWfOB7pkcdfGC/WW.PkWgqN0odHVy'),(42,'AIRA DWI MAULIDA',NULL,'3178039730','P',2,'$2y$10$aN5642LYkotRVmqx5vrMcOaWGCRUIaQ52mOCBSj0aqMeMxJpKbeRy'),(43,'AKHMAD SONY BINTANG ADITYA',NULL,'3174857338','L',2,'$2y$10$R0uYKErLeVhWCyn7vmY2s.PN8Gv250kjJwisMj.mOg8bVZtTzVY16'),(44,'ALIF SAPUTRA',NULL,'3181022161','L',2,'$2y$10$jq./WQ4D31z1kTEmBywsXuTMVv8/kVCtBi8kjkEUIKJitDnMOs9za'),(45,'ALMAQVIRA AULIA SHALIHA',NULL,'3175785706','P',2,'$2y$10$eBfBfVjE/nbEJkwLmdG9TOb1Q5OUyHAHbItUkIl0zBg4AkuNqDVpK'),(46,'AURA LATISHA AQUINA',NULL,'3181233911','P',2,'$2y$10$zpzsXE9Sm18GihEo.KV2femh7yzwn0zDIAB8QRJXcG50blWbUlYGi'),(47,'HAFIZ AL RASHAAD',NULL,'3171065604','L',2,'$2y$10$Yv4nvKHF94tvqUTkeD/CheKy5BazLlXn3EIuJZdVcj9EbE9Tzdlmy'),(48,'HANIA NATASHA ADINDA AZZAHRA',NULL,'3170498904','P',2,'$2y$10$Tp60bwN0ak6v/Kg4hOpNhOWk1G8V1k1w1lkUEJK8lpTFXxfYzDOt2'),(49,'MAHIR RIZQI ABDILLAH',NULL,'3171957808','L',2,'$2y$10$svpC/iVCewUYaNW/afrVcO2nMnikOC4MX3d9N.UWWaue9DBhZGCVm'),(50,'MAULANA SYAFIQ RAMADHAN',NULL,'3187786956','L',2,'$2y$10$BE5OzWzvp1BHYF081oo4w.RdSgu9GwFkjeFWM78HOBRYUu/.XYzCa'),(51,'MUHAMMAD AINUN NAJIB',NULL,'3189060879','L',2,'$2y$10$qywtHiW9FrLcZDQ2OqAfLuIgBpmai3kJc8YOVTuYmZjJgDq8/1Qra'),(52,'MUHAMMAD AZKA DHIYA’UL HAQ',NULL,'3187039124','L',2,'$2y$10$WHSprOvHDd9xkuhKVLONGubF/UFG0hleiy6NPiTzpdZt1rAqoyMfe'),(53,'MUHAMMAD HAFIZ MAULANA',NULL,'3189975601','L',2,'$2y$10$AU4i/K/.8/UI38CpZ7iFK.1ddBlGV63tHJfyfcXD6/9iIV2mdnG.K'),(54,'MUHAMMAD MAHDI',NULL,'3187106516','L',2,'$2y$10$p5TMq0l4o1HUejayFH.eG.ACc1zZkX2WcAa9a4y20wIJpCbgSODnC'),(55,'Muhammad Nadaril Saputra',NULL,'3175823960','L',2,'$2y$10$HMPHQwTPJOsdmwdhB5HiDuBraf/ObCla0L92PnWBS/bQwT.FcC4Z6'),(56,'MUHAMMAD RIZQI MAULANA',NULL,'3182266699','L',2,'$2y$10$GnEqMPZUa/a3ozT9KP50VurHrF8c.B7zrMTT5nHUvC2CgKUP5CEiu'),(57,'MUHAMMAD SABRIEL RAYYAN',NULL,'3180027333','L',2,'$2y$10$a3Vk..kQAKKt18QjNTjrVOb/ILSV00n/alXFSf0bIurxz3/a690SC'),(58,'Nikeisya Sherin Aqila',NULL,'0177572457','P',2,'$2y$10$B7/7gH/uW.A6GmkE8O3fqes9B3UcpHKpTweCttzw07Bs4cuiG3D.K'),(59,'NUR ERLINA ARDIRA',NULL,'3171432750','P',2,'$2y$10$YB8AHTPHerXbLVoHGvSfHODl1Cp9rdcHHqAehJ1LI.uDckwFha3Um'),(60,'RAHMA SHEKHA ADINDA PUTRI',NULL,'3176934840','P',2,'$2y$10$q8.bSd2e22A7wNFa22fR0uLOH7dKHTjs0JjBXm0DcyThbCivwfOhu'),(61,'Salsabilatun Najah',NULL,'3184286316','P',2,'$2y$10$IWvCcgjL6BQjinv6g2i1yetH7B//t.6PDpaI7XhFg6rba3dzV0Qiy'),(62,'SRI HANDAYANI',NULL,'3174413024','P',2,'$2y$10$CaexEcUIrRtvPeHptS8xGubkj0O3o7Ow6VoKczq3B4L1EP7BobO52'),(63,'ZALFA KHAIRUNNISA',NULL,'3175059536','P',2,'$2y$10$JoYhqYk34H8UkALMTda6tOssLj2A2JCTkMHGrCuhZdf7BEvvgLwly'),(64,'AKBAR AL HANAN',NULL,'3162535127','L',3,'$2y$10$iAXF5VtNx4yXX/Kj.B5v7OiHY8MaxeHkg0VzHhDzwAZYEZY4LMp0S'),(65,'ALMA NAFI\'A',NULL,'3169440189','P',3,'$2y$10$SqU.k5JaMVFYJlF13PeoDOMrWxFzwiti3B4gm8shu9hxEuLW0A5CG'),(66,'ALZAM MAUZA ALINDRA',NULL,'3164665566','L',3,'$2y$10$Erv0BrzEYFEciTSB1bw.ruyv7Zi7xaNcMDtqplUUpJiUJ87ayEUgS'),(67,'ANNISA MAZROATUL AHSANI',NULL,'0166159155','P',3,'$2y$10$Jd0XMZgxpi./0lOp2YRBbuWyR9WcGlJf2HxfY1YLv4sTFNY5DsL7u'),(68,'ARINI NIHAYATUS SHOLIHAH',NULL,'0163291739','P',3,'$2y$10$VG8lmptYK.6lCynpFXrUZeS4.wx/1yLDgm5QJWRD7GGp.Zm0ENcO2'),(69,'AURORA DIYATUL FILARDI',NULL,'3169539885','P',3,'$2y$10$FDIRJqGCKJ6mf1ZbZ5ejYuQZnWs7RBmoZ9AUjo5YjpML.z8lSjgRi'),(70,'CITRA DWI LESTARI',NULL,'3165720620','P',3,'$2y$10$i0lOo2EZYKnULunNo/3kfuPKpy6Bfijc754YXKvqKIoWrZmhp8zeq'),(71,'JOVAN PRATAMA',NULL,'3163104174','L',3,'$2y$10$.ef5o1NhUYN2.I1lmA99G.OcdSGt3H1h.rx4Uv5n0rx45H5d2cHue'),(72,'KAYLA ANANDA SELFIA',NULL,'3163851089','P',3,'$2y$10$zEgvul5s1pAEdfCSRaVVk.VJR/nycY9pl.LuT/y/QiG6gX/G3xwMG'),(73,'LAILATUZZAHRA',NULL,'3168585316','P',3,'$2y$10$dO4FMp9BJ.Vf5QajYIYV0.gKXPfr4R0Nv8t2p68lm3DFhg.q8hTF2'),(74,'MUHAMMAD ARDIAN ERFA SAPUTRA',NULL,'3168502077','L',3,'$2y$10$fmlFZv4jdc/3KnXw0jJ/heQWvPAXWDLetP9AbUjrYvzrnX9BVbJVm'),(75,'MUHAMMAD NUR YUSUF',NULL,'3169048580','L',3,'$2y$10$8..J/nS4TXoe9nvaqXfsR.o6Qm7PDz0srdfgdOfAwgBbsMen2AhBW'),(76,'NUR ALIFATUL ZAHIRA',NULL,'3177478000','P',3,'$2y$10$HkPkHTIfT511A23Q.FrIROPeRc2gMDUfsC3OW0qjvPo8jmNmL3j3e'),(77,'RAFA RASYIQUL UMAR',NULL,'3165088631','L',3,'$2y$10$F3JGz/ftzopE2VURTEF50.WxxiNeqgItfs.YRTXVHhvnQp8FQG6cm'),(78,'REVA RAMADHANI',NULL,'3163117591','P',3,'$2y$10$5fuypDcAvU6QmqfASutDieMKADNxoaib6rwdDL71l4J6bwKzmdEgG'),(79,'RIZQY AWWALUN PUTRA AHMAD',NULL,'3168692801','L',3,'$2y$10$CQ5C9I9.Vq6XRdjqKQCAJujWNlr1z9PjukjB547eAu3y4hsJlaqnO'),(80,'SRI AISYAH AILANI ARKA',NULL,'0166304991','P',3,'$2y$10$zKL9EGfIRS14vc24F.8fW.ugIXCUBJfY2Xk.CZRpbcq6MQF2WmBNq'),(81,'SYIFANA AWWALIYA',NULL,'3162918924','P',3,'$2y$10$XOdqeQztNN72jrBgHv12gukSBEXs2uM3b9ay2hOCQZT7OLd50x1ty'),(82,'TITANIA WICENZA SETYA',NULL,'3166299389','P',3,'$2y$10$EBtU1ALFpl4gfr2Ff3gR7OLgW6/7IeKlxMsdis5NQ0M5dKJxqawFG'),(83,'VEBY STEVANIE',NULL,'3151044603','P',3,'$2y$10$WEroFN5p6ST3LhpYSi3FJ.3WzlM3znCzGFUOYVCbRAkeJ/ePGHveS'),(84,'AHMAD DAFA SAPUTRA',NULL,'3157310312','L',4,'$2y$10$HcTvk.LbARnoQcHPLS4SxO4aBrPvtmQQ5y9ht.046MPjzT/xvM5nK'),(85,'ANDHARA NAURA LATIFAH',NULL,'0159344416','P',4,'$2y$10$iVIV0gLuw7hOy0Y1mePk.OUIML.oxQJeeWojwBZl25UlXjGGSFjG6'),(86,'ASTI DAYINTA ELOK WIGUNA',NULL,'3138636681','P',4,'$2y$10$BsZPnuyjNSbn7viHZL3zluIJaKQoFptbMwhXan5ZTW994EWBp1J3y'),(87,'AZKA ARDA YOGA',NULL,'0158273255','L',4,'$2y$10$gOmb4DhtvaB/tDMq9TTuPOGf0qM/0EKouNVIyQIa13em9.zHAqdje'),(88,'BATARI ARSYAVIDYA AHMAD',NULL,'0136511810','P',4,'$2y$10$5prDJP7nDlH1dikYIZaPzuqSJKofYQrhU0dnw0nM6L9TSTVbwjHwy'),(89,'FADLIL A\'LA',NULL,'3156182504','L',4,'$2y$10$2WN9wP/FZm/S8.R/EUeGZ.uMnEWvAWzpB.XnL533EBZPUvDFfrvcO'),(90,'HAYU FALISHA LAIL',NULL,'0159328720','P',4,'$2y$10$7WO.97MJ8C.TccMDrvEUiuy1p3LT6sK4xtpfxIvjVUKDSxlm0Wx4q'),(91,'MAFATIHUL KHOIR',NULL,'0154732385','P',4,'$2y$10$0hCklfyHherSsMFnxFnxCeO6E7PIQVXZqQyKR40EtAMw5SupmvGrO'),(92,'MUHAMMAD ALGA NOVA',NULL,'3151306061','L',4,'$2y$10$omeHHeDGUwrjmBJkx.L/BOs0ydVfq8ru0lBjbWl/.uXn1SAXW0zj6'),(93,'NILAM CAHYA RANI',NULL,'3154268018','P',4,'$2y$10$44NNuUsL9dh9AIkF2EHp0.N9n47mZ3kq4sNnkUVUUWjqmGCd0FOoK'),(94,'NOR ALVIAN AZ ZAHRAN',NULL,'0155498460','L',4,'$2y$10$2V7s/Vz2IOTdZHObAWOctOkx2AvdaWZp0VNrZtkzbhuvEBISE3QrK'),(95,'QIYANU JABAR MASA\'ID',NULL,'3164754146','L',4,'$2y$10$24HYk7kZuLamoGZVcTz81ekeeTFlh7PKP3U96.Q6J9zReC32IvswS'),(96,'RATU VIOLA ZAZKIA ANNISA',NULL,'3155089697','P',4,'$2y$10$aB9X0idMFoRPm5qsapXoGOjvWdw4cZA8Tw4WJQ9wPchIZlBLTYCR2'),(97,'AHMAD ARJUNNAJATA ILAMAULA',NULL,'0146901301','L',5,'$2y$10$TNsRglG3EgdFOmGIEQHlZuWPgJEj9T3Mt2p9TLF0HtbxY44UiZqaG'),(98,'AHMAD ARSYIL ROHAN',NULL,'0141948658','L',5,'$2y$10$kjmELtF/Id5N/0fl8nLoXeJyiPqt5v1UWE4yEvLIEKgqStGo/Qqq.'),(99,'ANGGITA CITRA ANINDITA',NULL,'0153488311','P',5,'$2y$10$4SUPaNGIAyxlQc6gtgMpb.sEjGSSWJrKBjjma89q.h4K1pJs.mQfe'),(100,'BILQIS ANINDITA HUDA',NULL,'3153353973','P',5,'$2y$10$gicrVNs1DHmocPGLCoRJe.GJBNtnZcUkTmBraPRZpfiZFKHK5b/.u'),(101,'EVA FANI VEBRIANI',NULL,'3140721776','P',5,'$2y$10$VOa/TvaHgFgjJAvF78YkEOPIGUpQJYik3H6i1.v5k2MdEewQhcGH6'),(102,'FARID LINTANG ARDIANSYAH',NULL,'0144317238','L',5,'$2y$10$nyyJoy0hgxd8JnDIHbrU7..rA/CVJAhQU1WdBhthLIBa6g.vfduc.'),(103,'FURJAH SABDA KENCANA',NULL,'0144565120','P',5,'$2y$10$fNkxvRqzW0UQfO/IMMcJbuUg0t.U/A/hrFjS1U99UZj.t5VqoZ2Vq'),(104,'MUHAMMAD BAYU ALHABSYI HIKMAWAN',NULL,'0146016714','L',5,'$2y$10$jaRObP1Aw6D9sOjKR0LzdO2XsReDokwJMwhoPaD4IwMwuEKc0c/si'),(105,'MUHAMMAD FAHRI ARDIANSYAH',NULL,'3148398924','L',5,'$2y$10$IDS3ilZDQLLQsBGK3a6tNu0OgnMiMfuO.9hW8jDYBwDTjP3fODM9i'),(106,'MUHAMMAD MIRZA RAMADHAN',NULL,'0134799480','L',5,'$2y$10$aSNPKWlo2Z9B6E7pedNbrO47uW3uNAtRRCuLSb2bJEqNS.mkXPn5K'),(107,'MUHAMMAD NAILUL FAIZ',NULL,'0145967375','L',5,'$2y$10$yrTg421dHKIsEJeWOGX.DeU8UZhqLXOcCuPx8fWkyyUCrhRXUYpYq'),(108,'MUHAMMAD ROMY PRAYADINATA',NULL,'0143035500','L',5,'$2y$10$Z6QpcpwG.NQIvFxYIiHvjuhvN0nXHqP2Jq2floWH/B8gsRe.RpJVK'),(109,'MUHAMMAD SAMSUL AD\'N',NULL,'3140219560','L',5,'$2y$10$7wSgl.GXpzUb5beZjBl40ub8td8hFB8K4oOcjKtxoD7C2ayV6dgbC'),(110,'NAF\'AN KAIS AHMAD',NULL,'3140985027','L',5,'$2y$10$GOW6FPjQRg9som60khFfmeNL3p5KYvsJe2vxDwWLhZDOiMipgAan.'),(111,'Rizal Arahman',NULL,'3130235731','L',5,'$2y$10$sZaKj/9P9JkACvJOuRGrP.9ovBEhCQZZjx3DCbOmvOnNJzkeGh07W'),(112,'SAILIN ANJA SAFARA',NULL,'0141467581','P',5,'$2y$10$nqjnwXdnMiB7z3jw5.3XVedq2yL/bBs5PizOAz3KeWWGkltw00pKW'),(113,'SEKAR NURI MAULIDA',NULL,'3132469215','P',5,'$2y$10$ESbr5DYk2r9O3owApYeLO.RGeENT6sZyPqCqzUeRDNP38xpJ3ihu2'),(114,'ZANETA AQILA ANDIENAMIRA',NULL,'3146697836','P',5,'$2y$10$.6YGroc3Kz/iBVcTrvwTrudAMN8fAAEgZSHRNEd6b0t68UTsex8t2'),(115,'ADIBA NUHA AZZAHRA',NULL,'3146588936','P',6,'$2y$10$AhUxjaEtaeGZ2A6Lc8eyOuBEbRdAOZN3JaHKATXgsClhfQR2Naplu'),(116,'Amrina Rosyada',NULL,'3137563185','P',6,'$2y$10$n97MFu6GQ73VG3544et2SOr3Em7xyHC6DXtEHUZvsSZFivrsSSvvq'),(117,'Aqilah Khoirurrosyadah',NULL,'3135628625','P',6,'$2y$10$lUCH4OVV8agCj5jPG/53suqkThapwYg05OA4171YZGmZJwbAL16im'),(118,'Bilqis Fahiya Rifda',NULL,'3132163433','P',6,'$2y$10$RFuAOGds4Nit9opwn74ehu3w1gS3dF7US5uGy.lxTGDSzDHtG5ER6'),(119,'Dewi Khuzaimah Annisa',NULL,'3138275600','P',6,'$2y$10$deasWlBm7eZzJ3vR.VcbvevcqATaGRCvymP4RX2w0cRhbx3CsJP1m'),(120,'Diyah Ayu Prawesti',NULL,'0137840437','P',6,'$2y$10$y505WD53PZ1Gfxjk7H77WennnniY1bBNx4fJnn20FXmq6VlrZ/5aG'),(121,'Dzakira Talita Azzahra',NULL,'3137847985','P',6,'$2y$10$Vxk5OEVfqqukOn5fKM1Jw.j3oOy5xbRu.SaFE/5RSvkKc2R5kSPNy'),(122,'Fabregas Alviano',NULL,'3133372371','L',6,'$2y$10$Bs185DSmv3iHLVTut/3sSevKmdShu2njxbRRKCNEvYZbZO6rn1d8.'),(123,'Indana Zulfa',NULL,'3146510193','P',6,'$2y$10$Y9BhPiQlkovVYZQNkRq5VOEpNmp0JGVwz17OPjW6t9WZyr2qLaet6'),(124,'Lidia Aura Citra',NULL,'3133041280','P',6,'$2y$10$pwOcqmzt4XFV87wkIy8hbuqCoujx65H.fY6oiWM0eoT1Xfo..msva'),(125,'Muhammad Agung Susilo Sugiono',NULL,'3149297726','L',6,'$2y$10$Z7Me28NlHDepJAbuwbj/cujkw4pSx8f70qk3hZcB.xuckAvBncZU6'),(126,'Muhammad Daris Alfurqon Aqim',NULL,'3130180823','L',6,'$2y$10$3BDlb8A2byhaShjO0YCNUuV2MQl4tWzhWPDZgrfip2wDZjJMtC9TC'),(127,'Muhammad Egi Ferdiansyah',NULL,'3140702123','L',6,'$2y$10$FdMmIIR6MO0Br82/VkLBUevGztL9Vf61oBv4Mflws2HanrsxaqHsS'),(128,'Muhammad Elga Saputra',NULL,'3130250384','L',6,'$2y$10$5EIf4K7Zxhen7ojE4cqmqesftlyvquMod38RmQPgP4L3PNWou1AUW'),(129,'Najwah Fadia Amalia Fitri',NULL,'3141710676','P',6,'$2y$10$DoajKv9AO5CjIqr16y3kXut2h4i9YNL14mtFARQi3XByJqO.fsHC6'),(130,'Putra Sadewa Saifunnawas',NULL,'3136264986','L',6,'$2y$10$NaK/5u21J4/WooFdH7mJceGVibNOWIUFs7Nxqphyrz928mKQlGXvm'),(131,'Rizquna Halalan Thoyyiba',NULL,'3137207114','P',6,'$2y$10$zlezIOvKrT/Q0RCOWiyJNemw94fn/moozLoqFu2rb/uvLfVIykH3W'),(132,'Siti Afifah Nauvalyn Fikriyah',NULL,'3131634863','P',6,'$2y$10$sStNnZ6ywtrbftbxcJLKUOhQmw2BqYtHmxKTqdkSOQpko2DTri8ka'),(133,'Siti Mei Listiana',NULL,'3139561428','P',6,'$2y$10$sRGsF6K7qSLT0Tq6jWbYOeTP.6htZLqOqN4Y5fD/09dFXEGcpGZMm');
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

-- Dump completed on 2026-01-31 15:47:11
