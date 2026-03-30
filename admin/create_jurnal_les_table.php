-- Create table for jurnal les
CREATE TABLE IF NOT EXISTS `tb_jurnal_les` (
  `id` int NOT NULL AUTO_INCREMENT,
  `id_kelas` int NOT NULL,
  `id_guru` int DEFAULT NULL,
  `waktu` varchar(50) NOT NULL,
  `mapel` varchar(100) NOT NULL,
  `materi` text NOT NULL,
  `tanggal` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `id_kelas` (`id_kelas`),
  KEY `id_guru` (`id_guru`),
  CONSTRAINT `tb_jurnal_les_ibfk_1` FOREIGN KEY (`id_kelas`) REFERENCES `tb_kelas` (`id_kelas`) ON DELETE CASCADE,
  CONSTRAINT `tb_jurnal_les_ibfk_2` FOREIGN KEY (`id_guru`) REFERENCES `tb_guru` (`id_guru`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
