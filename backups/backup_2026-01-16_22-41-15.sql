-- MySQL dump 10.13  Distrib 8.4.3, for Win64 (x86_64)
--
-- Host: localhost    Database: db_absensi
-- ------------------------------------------------------
-- Server version	8.0.30

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
) ENGINE=InnoDB AUTO_INCREMENT=168 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_absensi`
--

LOCK TABLES `tb_absensi` WRITE;
/*!40000 ALTER TABLE `tb_absensi` DISABLE KEYS */;
INSERT INTO `tb_absensi` VALUES (4,7,'2026-01-13',NULL,NULL,'Hadir',NULL),(5,8,'2026-01-13',NULL,NULL,'Hadir',NULL),(6,9,'2026-01-13',NULL,NULL,'Hadir',NULL),(12,7,'2026-01-14',NULL,NULL,'Hadir',NULL),(13,8,'2026-01-14',NULL,NULL,'Hadir',NULL),(14,9,'2026-01-14',NULL,NULL,'Sakit',NULL),(16,13,'2026-01-14',NULL,NULL,'Hadir',NULL),(19,16,'2026-01-14',NULL,NULL,'Hadir',NULL),(20,17,'2026-01-14',NULL,NULL,'Hadir',NULL),(21,18,'2026-01-14',NULL,NULL,'Hadir',NULL),(22,19,'2026-01-14',NULL,NULL,'Izin',NULL),(23,20,'2026-01-14',NULL,NULL,'Hadir',NULL),(24,21,'2026-01-14',NULL,NULL,'Izin',NULL),(32,7,'2026-01-15',NULL,NULL,'Hadir',NULL),(33,8,'2026-01-15',NULL,NULL,'Hadir',NULL),(34,9,'2026-01-15',NULL,NULL,'Izin',NULL),(35,19,'2026-01-15',NULL,NULL,'Hadir',NULL),(36,20,'2026-01-15',NULL,NULL,'Hadir',NULL),(37,21,'2026-01-15',NULL,NULL,'Sakit',NULL),(44,7,'2026-01-16',NULL,NULL,'Hadir',NULL),(45,8,'2026-01-16',NULL,NULL,'Hadir',NULL),(46,9,'2026-01-16',NULL,NULL,'Izin',NULL),(47,13,'2026-01-16',NULL,NULL,'Hadir',NULL),(50,16,'2026-01-16',NULL,NULL,'Hadir',NULL),(51,17,'2026-01-16',NULL,NULL,'Hadir',NULL),(52,18,'2026-01-16',NULL,NULL,'Sakit',NULL),(53,19,'2026-01-16',NULL,NULL,'Sakit',NULL),(54,20,'2026-01-16',NULL,NULL,'Alpa',NULL),(55,21,'2026-01-16',NULL,NULL,'Hadir',NULL),(56,22,'2026-01-16',NULL,NULL,'Hadir',NULL),(57,23,'2026-01-16',NULL,NULL,'Hadir',NULL),(58,24,'2026-01-16',NULL,NULL,'Hadir',NULL),(59,25,'2026-01-16',NULL,NULL,'Hadir',NULL),(60,26,'2026-01-16',NULL,NULL,'Hadir',NULL),(61,27,'2026-01-16',NULL,NULL,'Hadir',NULL),(62,28,'2026-01-16',NULL,NULL,'Hadir',NULL),(63,29,'2026-01-16',NULL,NULL,'Hadir',NULL),(64,30,'2026-01-16',NULL,NULL,'Sakit',NULL),(65,31,'2026-01-16',NULL,NULL,'Hadir',NULL),(66,32,'2026-01-16',NULL,NULL,'Hadir',NULL),(67,33,'2026-01-16',NULL,NULL,'Hadir',NULL),(68,34,'2026-01-16',NULL,NULL,'Hadir',NULL),(69,35,'2026-01-16',NULL,NULL,'Hadir',NULL),(70,36,'2026-01-16',NULL,NULL,'Sakit',NULL),(71,37,'2026-01-16',NULL,NULL,'Hadir',NULL),(72,38,'2026-01-16',NULL,NULL,'Hadir',NULL),(73,39,'2026-01-16',NULL,NULL,'Alpa',NULL),(74,40,'2026-01-16',NULL,NULL,'Hadir',NULL),(75,41,'2026-01-16',NULL,NULL,'Hadir',NULL),(76,42,'2026-01-16',NULL,NULL,'Hadir',NULL),(77,43,'2026-01-16',NULL,NULL,'Hadir',NULL),(78,44,'2026-01-16',NULL,NULL,'Hadir',NULL),(79,45,'2026-01-16',NULL,NULL,'Hadir',NULL),(80,46,'2026-01-16',NULL,NULL,'Hadir',NULL),(81,47,'2026-01-16',NULL,NULL,'Hadir',NULL),(82,48,'2026-01-16',NULL,NULL,'Hadir',NULL),(83,49,'2026-01-16',NULL,NULL,'Hadir',NULL),(84,50,'2026-01-16',NULL,NULL,'Hadir',NULL),(85,51,'2026-01-16',NULL,NULL,'Hadir',NULL),(86,52,'2026-01-16',NULL,NULL,'Hadir',NULL),(87,53,'2026-01-16',NULL,NULL,'Hadir',NULL),(88,54,'2026-01-16',NULL,NULL,'Hadir',NULL),(89,55,'2026-01-16',NULL,NULL,'Hadir',NULL),(90,56,'2026-01-16',NULL,NULL,'Hadir',NULL),(91,57,'2026-01-16',NULL,NULL,'Hadir',NULL),(92,58,'2026-01-16',NULL,NULL,'Hadir',NULL),(93,59,'2026-01-16',NULL,NULL,'Hadir',NULL),(94,60,'2026-01-16',NULL,NULL,'Hadir',NULL),(95,61,'2026-01-16',NULL,NULL,'Hadir',NULL),(96,62,'2026-01-16',NULL,NULL,'Hadir',NULL),(97,63,'2026-01-16',NULL,NULL,'Hadir',NULL),(98,64,'2026-01-16',NULL,NULL,'Hadir',NULL),(99,65,'2026-01-16',NULL,NULL,'Sakit',NULL),(100,66,'2026-01-16',NULL,NULL,'Hadir',NULL),(101,67,'2026-01-16',NULL,NULL,'Hadir',NULL),(102,68,'2026-01-16',NULL,NULL,'Hadir',NULL),(103,69,'2026-01-16',NULL,NULL,'Hadir',NULL),(104,70,'2026-01-16',NULL,NULL,'Hadir',NULL),(105,71,'2026-01-16',NULL,NULL,'Hadir',NULL),(106,72,'2026-01-16',NULL,NULL,'Hadir',NULL),(107,73,'2026-01-16',NULL,NULL,'Hadir',NULL),(108,74,'2026-01-16',NULL,NULL,'Hadir',NULL),(109,75,'2026-01-16',NULL,NULL,'Hadir',NULL),(110,76,'2026-01-16',NULL,NULL,'Hadir',NULL),(111,77,'2026-01-16',NULL,NULL,'Hadir',NULL),(112,78,'2026-01-16',NULL,NULL,'Hadir',NULL),(113,79,'2026-01-16',NULL,NULL,'Hadir',NULL),(114,80,'2026-01-16',NULL,NULL,'Hadir',NULL),(115,81,'2026-01-16',NULL,NULL,'Hadir',NULL),(116,82,'2026-01-16',NULL,NULL,'Hadir',NULL),(117,83,'2026-01-16',NULL,NULL,'Hadir',NULL),(118,84,'2026-01-16',NULL,NULL,'Hadir',NULL),(119,85,'2026-01-16',NULL,NULL,'Hadir',NULL),(120,86,'2026-01-16',NULL,NULL,'Hadir',NULL),(121,87,'2026-01-16',NULL,NULL,'Hadir',NULL),(122,88,'2026-01-16',NULL,NULL,'Hadir',NULL),(123,89,'2026-01-16',NULL,NULL,'Hadir',NULL),(124,90,'2026-01-16',NULL,NULL,'Hadir',NULL),(125,91,'2026-01-16',NULL,NULL,'Hadir',NULL),(126,92,'2026-01-16',NULL,NULL,'Hadir',NULL),(127,93,'2026-01-16',NULL,NULL,'Hadir',NULL),(128,94,'2026-01-16',NULL,NULL,'Hadir',NULL),(129,95,'2026-01-16',NULL,NULL,'Hadir',NULL),(130,96,'2026-01-16',NULL,NULL,'Alpa',NULL),(131,97,'2026-01-16',NULL,NULL,'Hadir',NULL),(132,98,'2026-01-16',NULL,NULL,'Hadir',NULL),(133,99,'2026-01-16',NULL,NULL,'Hadir',NULL),(134,100,'2026-01-16',NULL,NULL,'Hadir',NULL),(135,101,'2026-01-16',NULL,NULL,'Hadir',NULL),(136,102,'2026-01-16',NULL,NULL,'Hadir',NULL),(137,103,'2026-01-16',NULL,NULL,'Alpa',NULL),(138,104,'2026-01-16',NULL,NULL,'Hadir',NULL),(139,105,'2026-01-16',NULL,NULL,'Izin',NULL),(140,106,'2026-01-16',NULL,NULL,'Hadir',NULL),(141,107,'2026-01-16',NULL,NULL,'Hadir',NULL),(142,108,'2026-01-16',NULL,NULL,'Hadir',NULL),(143,109,'2026-01-16',NULL,NULL,'Hadir',NULL),(144,110,'2026-01-16',NULL,NULL,'Hadir',NULL),(145,111,'2026-01-16',NULL,NULL,'Hadir',NULL),(146,112,'2026-01-16',NULL,NULL,'Hadir',NULL),(147,113,'2026-01-16',NULL,NULL,'Alpa',NULL),(148,114,'2026-01-16',NULL,NULL,'Hadir',NULL),(149,115,'2026-01-16',NULL,NULL,'Hadir',NULL),(150,116,'2026-01-16',NULL,NULL,'Hadir',NULL),(151,117,'2026-01-16',NULL,NULL,'Hadir',NULL),(152,118,'2026-01-16',NULL,NULL,'Hadir',NULL),(153,119,'2026-01-16',NULL,NULL,'Hadir',NULL),(154,120,'2026-01-16',NULL,NULL,'Hadir',NULL),(155,121,'2026-01-16',NULL,NULL,'Hadir',NULL),(156,122,'2026-01-16',NULL,NULL,'Hadir',NULL),(157,123,'2026-01-16',NULL,NULL,'Hadir',NULL),(158,124,'2026-01-16',NULL,NULL,'Hadir',NULL),(159,125,'2026-01-16',NULL,NULL,'Hadir',NULL),(160,126,'2026-01-16',NULL,NULL,'Hadir',NULL),(161,127,'2026-01-16',NULL,NULL,'Hadir',NULL),(162,128,'2026-01-16',NULL,NULL,'Alpa',NULL),(163,129,'2026-01-16',NULL,NULL,'Hadir',NULL),(164,130,'2026-01-16',NULL,NULL,'Hadir',NULL),(165,131,'2026-01-16',NULL,NULL,'Hadir',NULL),(166,132,'2026-01-16',NULL,NULL,'Hadir',NULL),(167,133,'2026-01-16',NULL,NULL,'Hadir',NULL);
/*!40000 ALTER TABLE `tb_absensi` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tb_activity_log`
--

DROP TABLE IF EXISTS `tb_activity_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_activity_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `action` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
  `description` text COLLATE utf8mb4_general_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=188 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_activity_log`
--

LOCK TABLES `tb_activity_log` WRITE;
/*!40000 ALTER TABLE `tb_activity_log` DISABLE KEYS */;
INSERT INTO `tb_activity_log` VALUES (103,'admin','Update Guru','Memperbarui data guru: Muhamad Junaedi','127.0.0.1','2026-01-16 00:47:38'),(104,'admin','Update Kelas','Memperbarui data kelas: I','127.0.0.1','2026-01-16 00:47:55'),(105,'admin','Update Kelas','Memperbarui data kelas: II','127.0.0.1','2026-01-16 00:48:04'),(106,'admin','Update Kelas','Memperbarui data kelas: III','127.0.0.1','2026-01-16 00:48:12'),(107,'admin','Update Kelas','Memperbarui data kelas: IV','127.0.0.1','2026-01-16 00:48:24'),(108,'admin','Update Kelas','Memperbarui data kelas: V','127.0.0.1','2026-01-16 00:48:32'),(109,'admin','Update Kelas','Memperbarui data kelas: VI','127.0.0.1','2026-01-16 00:48:41'),(110,'admin','Update Guru','Memperbarui data guru: Abdul Ghofur, S.Pd.I','127.0.0.1','2026-01-16 00:49:46'),(111,'admin','Update Guru','Memperbarui data guru: Abdul Ghofur, S.Pd.I','127.0.0.1','2026-01-16 00:50:03'),(112,'admin','Update Guru','Memperbarui data guru: Abdul Ghofur, S.Pd.I','127.0.0.1','2026-01-16 00:51:20'),(113,'admin','Update Guru','Memperbarui data guru: Ah. Mustaqim Isom, A.Ma.','127.0.0.1','2026-01-16 00:51:40'),(114,'admin','Update Guru','Memperbarui data guru: Alfina Martha Sintya, S.Pd.','127.0.0.1','2026-01-16 00:52:07'),(115,'admin','Update Guru','Memperbarui data guru: Ali Yasin, S.Pd.I','127.0.0.1','2026-01-16 00:52:27'),(116,'admin','Update Guru','Memperbarui data guru: Hamidah, A.Ma.','127.0.0.1','2026-01-16 00:52:56'),(117,'admin','Update Guru','Memperbarui data guru: Indasah, A.Ma.','127.0.0.1','2026-01-16 00:53:15'),(118,'admin','Update Guru','Memperbarui data guru: Khoiruddin, S.Pd.','127.0.0.1','2026-01-16 00:53:28'),(119,'admin','Update Guru','Memperbarui data guru: Musri`ah, S.Pd.I','127.0.0.1','2026-01-16 00:53:42'),(120,'admin','Update Guru','Memperbarui data guru: Nanik Purwati, S.Pd.I','127.0.0.1','2026-01-16 00:53:54'),(121,'admin','Update Guru','Memperbarui data guru: Nur Hidah, S.Pd.I.','127.0.0.1','2026-01-16 00:54:05'),(122,'admin','Update Guru','Memperbarui data guru: Nur Huda, S.Pd.I.','127.0.0.1','2026-01-16 00:54:16'),(123,'admin','Update Guru','Memperbarui data guru: Zama`ah, S.Pd.I.','127.0.0.1','2026-01-16 00:54:26'),(124,'admin','Update Guru','Memperbarui data guru: Zama`ah, S.Pd.I.','127.0.0.1','2026-01-16 00:57:13'),(125,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 1','127.0.0.1','2026-01-16 01:15:03'),(126,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 2','127.0.0.1','2026-01-16 01:15:10'),(127,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 3','127.0.0.1','2026-01-16 01:15:13'),(128,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 4','127.0.0.1','2026-01-16 01:15:19'),(129,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 5','127.0.0.1','2026-01-16 01:15:24'),(130,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 6','127.0.0.1','2026-01-16 01:15:30'),(131,'admin','Hapus Siswa','Menghapus data siswa: Santoso botak2','127.0.0.1','2026-01-16 01:24:45'),(132,'admin','Hapus Siswa','Menghapus data siswa: Pidin Saripudin','127.0.0.1','2026-01-16 01:24:51'),(133,'admin','Hapus Siswa','Menghapus data siswa: Pak Ogah-ogah','127.0.0.1','2026-01-16 01:24:57'),(134,'admin','Hapus Siswa','Menghapus data siswa: Andi Pratama','127.0.0.1','2026-01-16 03:21:06'),(135,'admin','Hapus Siswa','Menghapus data siswa: Budi Santoso','127.0.0.1','2026-01-16 03:21:14'),(136,'admin','Hapus Siswa','Menghapus data siswa: Citra Dewi','127.0.0.1','2026-01-16 03:22:07'),(137,'admin','Login','User logged in successfully','127.0.0.1','2026-01-16 04:24:23'),(138,'admin','Transfer Siswa','Memindahkan siswa Citra Dewi dari kelas III ke kelas II','127.0.0.1','2026-01-16 04:37:57'),(139,'admin','Transfer Siswa','Memindahkan siswa Budi Santoso dari kelas III ke kelas II','127.0.0.1','2026-01-16 04:38:16'),(140,'admin','Transfer Siswa','Memindahkan siswa Santoso dari kelas IV ke kelas III','127.0.0.1','2026-01-16 04:38:31'),(141,'admin','Login','User logged in successfully','127.0.0.1','2026-01-16 05:53:09'),(142,'admin','Bulk Edit Guru','Memperbarui 2 data guru','127.0.0.1','2026-01-16 07:54:16'),(143,'admin','Bulk Edit Guru','Memperbarui 3 data guru','127.0.0.1','2026-01-16 07:56:13'),(144,'admin','Update Guru','Memperbarui data guru: Nur Huda, S.Pd.I.','127.0.0.1','2026-01-16 08:12:23'),(145,'admin','Update Guru','Memperbarui data guru: Nur Huda, S.Pd.I.','127.0.0.1','2026-01-16 08:14:24'),(146,'admin','Update Guru','Memperbarui data guru: Nur Huda, S.Pd.I.','127.0.0.1','2026-01-16 08:23:32'),(147,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 1','127.0.0.1','2026-01-16 10:33:04'),(148,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 1','127.0.0.1','2026-01-16 10:33:07'),(149,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 5','127.0.0.1','2026-01-16 11:12:44'),(150,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 4','127.0.0.1','2026-01-16 11:12:52'),(151,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 3','127.0.0.1','2026-01-16 11:12:59'),(152,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 2','127.0.0.1','2026-01-16 11:13:09'),(153,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 1','127.0.0.1','2026-01-16 11:13:20'),(154,'7841746648200002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-16 12:45:19'),(155,'7841746648200002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-16 13:08:42'),(156,'admin','Login','User logged in successfully','127.0.0.1','2026-01-16 13:21:05'),(157,'admin','Update Guru','Memperbarui data guru: Indasah, A.Ma.','127.0.0.1','2026-01-16 13:21:20'),(158,'2640755657300002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-16 13:21:37'),(159,'admin','Login','User logged in successfully','127.0.0.1','2026-01-16 13:21:56'),(160,'5436757658200002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-16 13:22:20'),(161,'admin','Login','User logged in successfully','127.0.0.1','2026-01-16 13:28:08'),(162,'admin','Hapus Siswa','Menghapus data siswa: Nur','127.0.0.1','2026-01-16 13:33:41'),(163,'admin','Hapus Siswa','Menghapus data siswa: Iwan','127.0.0.1','2026-01-16 13:33:47'),(164,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 2','127.0.0.1','2026-01-16 13:41:25'),(165,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 3','127.0.0.1','2026-01-16 13:41:32'),(166,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 4','127.0.0.1','2026-01-16 13:41:40'),(167,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 5','127.0.0.1','2026-01-16 13:41:50'),(168,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 6','127.0.0.1','2026-01-16 13:42:08'),(169,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 6','127.0.0.1','2026-01-16 13:42:43'),(170,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 5','127.0.0.1','2026-01-16 13:42:58'),(171,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 6 untuk 2 siswa','127.0.0.1','2026-01-16 13:49:38'),(172,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 6 untuk 22 siswa','127.0.0.1','2026-01-16 13:54:21'),(173,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 5 untuk 21 siswa','127.0.0.1','2026-01-16 13:55:26'),(174,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 4 untuk 13 siswa','127.0.0.1','2026-01-16 13:55:57'),(175,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 3 untuk 22 siswa','127.0.0.1','2026-01-16 13:56:42'),(176,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 2 untuk 26 siswa','127.0.0.1','2026-01-16 13:57:22'),(177,'admin','Input Absensi','Admin admin melakukan input absensi harian kelas ID: 1 untuk 18 siswa','127.0.0.1','2026-01-16 13:57:53'),(178,'5436757658200002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-16 14:05:48'),(179,'2640755657300002','Login','Teacher logged in successfully using NUPTK','127.0.0.1','2026-01-16 14:48:21'),(180,'admin','Login','User logged in successfully','127.0.0.1','2026-01-16 14:48:40'),(181,'admin','Update Pengguna','Memperbarui data pengguna: Admin (level: admin)','127.0.0.1','2026-01-16 14:57:15'),(182,'admin','Update Pengguna','Memperbarui data pengguna: Admin (level: admin)','127.0.0.1','2026-01-16 14:57:20'),(183,'admin','Update Guru','Memperbarui data guru: Abdul Ghofur, S.Pd.I','127.0.0.1','2026-01-16 15:17:22'),(184,'admin','Update Guru','Memperbarui data guru: Alfina Martha Sintya, S.Pd.','127.0.0.1','2026-01-16 15:18:34'),(185,'admin','Update Guru','Memperbarui data guru: Alfina Martha Sintya, S.Pd.','127.0.0.1','2026-01-16 15:28:45'),(186,'admin','Update Kelas','Memperbarui data kelas: I','127.0.0.1','2026-01-16 15:29:38'),(187,'admin','Update Guru','Memperbarui data guru: Alfina Martha Sintya, S.Pd.','127.0.0.1','2026-01-16 15:34:26');
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_backup_restore`
--

LOCK TABLES `tb_backup_restore` WRITE;
/*!40000 ALTER TABLE `tb_backup_restore` DISABLE KEYS */;
INSERT INTO `tb_backup_restore` VALUES (1,'backup_2026-01-13_10-59-31.sql','2026-01-13 17:59:31','7.95 KB','Backup manual'),(2,'backup_2026-01-16_21-05-12.sql','2026-01-16 21:05:15','32.38 KB','Backup manual');
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
INSERT INTO `tb_guru` VALUES (18,'Abdul Ghofur, S.Pd.I','2444764667200003','Jepara','1986-11-12','Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"5\"]',NULL),(19,'Nur Huda, S.Pd.I.','5436757658200002','Jepara','1979-01-04','Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"6\"]','guru_1768551812_nur huda.jpeg'),(20,'Ah. Mustaqim Isom, A.Ma.','7841746648200002','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"V\",\"VI\"]',NULL),(21,'Alfina Martha Sintya, S.Pd.','33200111223','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"4\"]',NULL),(22,'Ali Yasin, S.Pd.I','9547746647110022','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"III\"]',NULL),(23,'Hamidah, A.Ma.','4444747649200002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"II\"]',NULL),(24,'Indasah, A.Ma.','2640755657300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"1\",\"2\"]',NULL),(25,'Khoiruddin, S.Pd.','ID20318581190001','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"V\"]',NULL),(26,'Musri`ah, S.Pd.I','6956748651300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"III\"]',NULL),(27,'Nanik Purwati, S.Pd.I','6556755656300002','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"IV\"]',NULL),(28,'Nur Hidah, S.Pd.I.','7357760661300003','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"II\"]',NULL),(29,'Zama`ah, S.Pd.I.','8041756657300003','Jepara',NULL,'Perempuan',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"I\"]',NULL),(30,'Muhamad Junaedi','8552750652200002','Jepara',NULL,'Laki-laki',NULL,'$2y$10$ABRi.HfwsMXmLL0.dgdxJ.Sqk4erWtVyzzmS0v3EEzoUMoKcLBiuW','123456','[\"V\",\"VI\"]',NULL);
/*!40000 ALTER TABLE `tb_guru` ENABLE KEYS */;
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
-- Table structure for table `tb_pengguna`
--

DROP TABLE IF EXISTS `tb_pengguna`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tb_pengguna` (
  `id_pengguna` int NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `level` enum('admin','guru','wali') NOT NULL,
  `id_guru` int DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id_pengguna`),
  KEY `id_guru` (`id_guru`),
  CONSTRAINT `tb_pengguna_ibfk_1` FOREIGN KEY (`id_guru`) REFERENCES `tb_guru` (`id_guru`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_pengguna`
--

LOCK TABLES `tb_pengguna` WRITE;
/*!40000 ALTER TABLE `tb_pengguna` DISABLE KEYS */;
INSERT INTO `tb_pengguna` VALUES (1,'Admin','$2y$10$AGvfJEpOAtAWYnws4pFgRutCpx3cjBB7tT5OzabhkHgR.HceF7ZIq','admin',NULL,NULL);
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
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tb_profil_madrasah`
--

LOCK TABLES `tb_profil_madrasah` WRITE;
/*!40000 ALTER TABLE `tb_profil_madrasah` DISABLE KEYS */;
INSERT INTO `tb_profil_madrasah` VALUES (1,'MI Sultan Fattah Sukosono','Musriah, S.Pd.I.','2025/2026','Semester 2','logo_1768301957.png');
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
INSERT INTO `tb_siswa` VALUES (7,'Andi Pratama','12345678901',NULL,3),(8,'Budi Santoso','12345678902','L',2),(9,'Citra Dewi','12345678903','P',2),(13,'Santoso','12345678901','L',3),(16,'Santoso','12345678901',NULL,5),(17,'Iwan','12345678902',NULL,5),(18,'Nur','12345678903',NULL,5),(19,'Santoso','12345678901','L',6),(20,'Iwan','12345678902','L',6),(21,'Nur','12345678903','P',6),(22,'ABDULLAH HASAN','3184602457','L',1),(23,'ABIZAR HABIBILLAH','3184275775','L',1),(24,'ADHITAMA ELVAN SYAHREZA','3180229036','L',1),(25,'AHMAD MANUTHO MUHAMMAD','3182663303','L',1),(26,'AIRA ZAHWA SAFIRA','3194980092','P',1),(27,'ARFAN MIYAZ ALINDRA','3182355082','L',1),(28,'DELISA ALYA SAFIQNA','3195153075','P',1),(29,'DIAN AIRA','3195813730','P',1),(30,'DHIRA QALESYA','3184245017','P',1),(31,'HIBAT ALMALIK','3183882033','L',1),(32,'JIHAN FADHILLAH','3194274202','P',1),(33,'KAYLA PUTRI AMALIA','3177681680','P',1),(34,'LAILATUL JANNATU AZZA','3190992049','P',1),(35,'MAUWAFIQ KHOIRUL FAJAR','3172404776','L',1),(36,'NORREIN NABIHA','3198116081','P',1),(37,'RHEVA PUTRI RAMADHANI','3186829907','P',1),(38,'SALMA SHAFIRA RAYYANA','3188013385','P',1),(39,'TUSAMMA SALSABILA','3184514039','P',1),(40,'ABIZARD ALTAN MUTTAQI','3179401623','L',2),(41,'AFWAN SETYO MANGGALA PUTRA','3174187068','L',2),(42,'AIRA DWI MAULIDA','3178039730','P',2),(43,'AKHMAD SONY BINTANG ADITYA','3174857338','L',2),(44,'ALIF SAPUTRA','3181022161','L',2),(45,'ALMAQVIRA AULIA SHALIHA','3175785706','P',2),(46,'AURA LATISHA AQUINA','3181233911','P',2),(47,'HAFIZ AL RASHAAD','3171065604','L',2),(48,'HANIA NATASHA ADINDA AZZAHRA','3170498904','P',2),(49,'MAHIR RIZQI ABDILLAH','3171957808','L',2),(50,'MAULANA SYAFIQ RAMADHAN','3187786956','L',2),(51,'MUHAMMAD AINUN NAJIB','3189060879','L',2),(52,'MUHAMMAD AZKA DHIYA’UL HAQ','3187039124','L',2),(53,'MUHAMMAD HAFIZ MAULANA','3189975601','L',2),(54,'MUHAMMAD MAHDI','3187106516','L',2),(55,'Muhammad Nadaril Saputra','3175823960','L',2),(56,'MUHAMMAD RIZQI MAULANA','3182266699','L',2),(57,'MUHAMMAD SABRIEL RAYYAN','3180027333','L',2),(58,'Nikeisya Sherin Aqila','0177572457','P',2),(59,'NUR ERLINA ARDIRA','3171432750','P',2),(60,'RAHMA SHEKHA ADINDA PUTRI','3176934840','P',2),(61,'Salsabilatun Najah','3184286316','P',2),(62,'SRI HANDAYANI','3174413024','P',2),(63,'ZALFA KHAIRUNNISA','3175059536','P',2),(64,'AKBAR AL HANAN','3162535127','L',3),(65,'ALMA NAFI\'A','3169440189','P',3),(66,'ALZAM MAUZA ALINDRA','3164665566','L',3),(67,'ANNISA MAZROATUL AHSANI','0166159155','P',3),(68,'ARINI NIHAYATUS SHOLIHAH','0163291739','P',3),(69,'AURORA DIYATUL FILARDI','3169539885','P',3),(70,'CITRA DWI LESTARI','3165720620','P',3),(71,'JOVAN PRATAMA','3163104174','L',3),(72,'KAYLA ANANDA SELFIA','3163851089','P',3),(73,'LAILATUZZAHRA','3168585316','P',3),(74,'MUHAMMAD ARDIAN ERFA SAPUTRA','3168502077','L',3),(75,'MUHAMMAD NUR YUSUF','3169048580','L',3),(76,'NUR ALIFATUL ZAHIRA','3177478000','P',3),(77,'RAFA RASYIQUL UMAR','3165088631','L',3),(78,'REVA RAMADHANI','3163117591','P',3),(79,'RIZQY AWWALUN PUTRA AHMAD','3168692801','L',3),(80,'SRI AISYAH AILANI ARKA','0166304991','P',3),(81,'SYIFANA AWWALIYA','3162918924','P',3),(82,'TITANIA WICENZA SETYA','3166299389','P',3),(83,'VEBY STEVANIE','3151044603','P',3),(84,'AHMAD DAFA SAPUTRA','3157310312','L',4),(85,'ANDHARA NAURA LATIFAH','0159344416','P',4),(86,'ASTI DAYINTA ELOK WIGUNA','3138636681','P',4),(87,'AZKA ARDA YOGA','0158273255','L',4),(88,'BATARI ARSYAVIDYA AHMAD','0136511810','P',4),(89,'FADLIL A\'LA','3156182504','L',4),(90,'HAYU FALISHA LAIL','0159328720','P',4),(91,'MAFATIHUL KHOIR','0154732385','P',4),(92,'MUHAMMAD ALGA NOVA','3151306061','L',4),(93,'NILAM CAHYA RANI','3154268018','P',4),(94,'NOR ALVIAN AZ ZAHRAN','0155498460','L',4),(95,'QIYANU JABAR MASA\'ID','3164754146','L',4),(96,'RATU VIOLA ZAZKIA ANNISA','3155089697','P',4),(97,'AHMAD ARJUNNAJATA ILAMAULA','0146901301','L',5),(98,'AHMAD ARSYIL ROHAN','0141948658','L',5),(99,'ANGGITA CITRA ANINDITA','0153488311','P',5),(100,'BILQIS ANINDITA HUDA','3153353973','P',5),(101,'EVA FANI VEBRIANI','3140721776','P',5),(102,'FARID LINTANG ARDIANSYAH','0144317238','L',5),(103,'FURJAH SABDA KENCANA','0144565120','P',5),(104,'MUHAMMAD BAYU ALHABSYI HIKMAWAN','0146016714','L',5),(105,'MUHAMMAD FAHRI ARDIANSYAH','3148398924','L',5),(106,'MUHAMMAD MIRZA RAMADHAN','0134799480','L',5),(107,'MUHAMMAD NAILUL FAIZ','0145967375','L',5),(108,'MUHAMMAD ROMY PRAYADINATA','0143035500','L',5),(109,'MUHAMMAD SAMSUL AD\'N','3140219560','L',5),(110,'NAF\'AN KAIS AHMAD','3140985027','L',5),(111,'Rizal Arahman','3130235731','L',5),(112,'SAILIN ANJA SAFARA','0141467581','P',5),(113,'SEKAR NURI MAULIDA','3132469215','P',5),(114,'ZANETA AQILA ANDIENAMIRA','3146697836','P',5),(115,'ADIBA NUHA AZZAHRA','3146588936','P',6),(116,'Amrina Rosyada','3137563185','P',6),(117,'Aqilah Khoirurrosyadah','3135628625','P',6),(118,'Bilqis Fahiya Rifda','3132163433','P',6),(119,'Dewi Khuzaimah Annisa','3138275600','P',6),(120,'Diyah Ayu Prawesti','0137840437','P',6),(121,'Dzakira Talita Azzahra','3137847985','P',6),(122,'Fabregas Alviano','3133372371','L',6),(123,'Indana Zulfa','3146510193','P',6),(124,'Lidia Aura Citra','3133041280','P',6),(125,'Muhammad Agung Susilo Sugiono','3149297726','L',6),(126,'Muhammad Daris Alfurqon Aqim','3130180823','L',6),(127,'Muhammad Egi Ferdiansyah','3140702123','L',6),(128,'Muhammad Elga Saputra','3130250384','L',6),(129,'Najwah Fadia Amalia Fitri','3141710676','P',6),(130,'Putra Sadewa Saifunnawas','3136264986','L',6),(131,'Rizquna Halalan Thoyyiba','3137207114','P',6),(132,'Siti Afifah Nauvalyn Fikriyah','3131634863','P',6),(133,'Siti Mei Listiana','3139561428','P',6);
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

-- Dump completed on 2026-01-16 22:41:16
