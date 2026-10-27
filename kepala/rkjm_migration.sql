-- ============================================================
-- Migrasi Fitur RKJM (Rencana Kerja Jangka Menengah) - SIMAD
-- Aman dijalankan berulang pada database berisi data.
-- Tidak menghapus tabel atau mereset data yang sudah ada.
--
-- Catatan: aplikasi juga membuat tabel ini otomatis (CREATE TABLE
-- IF NOT EXISTS) saat halaman RKJM pertama kali dibuka, lalu mengisi
-- bidang pengembangan + 66 template program bawaan jika masih kosong.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `rkjm_fields` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `kode` VARCHAR(20) NOT NULL,
  `nama` VARCHAR(255) NOT NULL,
  `urutan` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_rkjm_fields_urutan` (`urutan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rkjm_documents` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `nama_madrasah` VARCHAR(255) NULL,
  `nomor_dokumen` VARCHAR(100) NULL,
  `tahun_awal` YEAR NOT NULL,
  `tahun_akhir` YEAR NOT NULL,
  `visi` TEXT NULL,
  `misi` TEXT NULL,
  `tujuan_pengembangan` TEXT NULL,
  `kondisi_awal` TEXT NULL,
  `dasar_penyusunan` TEXT NULL,
  `tanggal_penyusunan` DATE NULL,
  `nama_kepala` VARCHAR(255) NULL,
  `status` ENUM('draf','penyusunan','final') NOT NULL DEFAULT 'draf',
  `created_by` VARCHAR(100) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_rkjm_doc_periode` (`tahun_awal`, `tahun_akhir`),
  INDEX `idx_rkjm_doc_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rkjm_template_programs` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `kode` VARCHAR(30) NOT NULL,
  `field_id` INT NULL,
  `bidang_nama` VARCHAR(255) NULL,
  `nama_program` VARCHAR(255) NOT NULL,
  `latar_belakang` TEXT NULL,
  `permasalahan` TEXT NULL,
  `tujuan` TEXT NULL,
  `sasaran` TEXT NULL,
  `indikator` TEXT NULL,
  `target_akhir` TEXT NULL,
  `target_tahun1` TEXT NULL,
  `target_tahun2` TEXT NULL,
  `target_tahun3` TEXT NULL,
  `target_tahun4` TEXT NULL,
  `kegiatan_utama` TEXT NULL,
  `penanggung_jawab` VARCHAR(255) NULL,
  `estimasi_anggaran` DECIMAL(15,2) DEFAULT 0,
  `sumber_dana` VARCHAR(255) NULL,
  `dokumen_bukti` TEXT NULL,
  `monitoring_evaluasi` TEXT NULL,
  `catatan` TEXT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_rkjm_tpl_field` (`field_id`),
  INDEX `idx_rkjm_tpl_active` (`is_active`),
  INDEX `idx_rkjm_tpl_kode` (`kode`),
  CONSTRAINT `fk_rkjm_tpl_field` FOREIGN KEY (`field_id`) REFERENCES `rkjm_fields` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rkjm_programs` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `document_id` INT NOT NULL,
  `template_id` INT NULL,
  `field_id` INT NULL,
  `bidang_nama` VARCHAR(255) NULL,
  `kode_program` VARCHAR(30) NULL,
  `nama_program` VARCHAR(255) NOT NULL,
  `kondisi_awal` TEXT NULL,
  `permasalahan` TEXT NULL,
  `tujuan` TEXT NULL,
  `sasaran` TEXT NULL,
  `indikator` TEXT NULL,
  `target_akhir` TEXT NULL,
  `penanggung_jawab` VARCHAR(255) NULL,
  `estimasi_anggaran` DECIMAL(15,2) DEFAULT 0,
  `sumber_dana` VARCHAR(255) NULL,
  `dokumen_bukti` TEXT NULL,
  `monitoring_evaluasi` TEXT NULL,
  `kegiatan_utama` TEXT NULL,
  `catatan` TEXT NULL,
  `status` ENUM('belum_dimulai','berjalan','tercapai','tidak_tercapai','ditunda') DEFAULT 'belum_dimulai',
  `urutan` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_rkjm_prog_doc` (`document_id`),
  INDEX `idx_rkjm_prog_field` (`field_id`),
  INDEX `idx_rkjm_prog_status` (`status`),
  INDEX `idx_rkjm_prog_tpl` (`template_id`),
  CONSTRAINT `fk_rkjm_prog_doc` FOREIGN KEY (`document_id`) REFERENCES `rkjm_documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rkjm_prog_field` FOREIGN KEY (`field_id`) REFERENCES `rkjm_fields` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_rkjm_prog_tpl` FOREIGN KEY (`template_id`) REFERENCES `rkjm_template_programs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rkjm_program_targets` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `program_id` INT NOT NULL,
  `tahun_ke` TINYINT NOT NULL,
  `tahun_kalender` YEAR NULL,
  `target` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_rkjm_target` (`program_id`, `tahun_ke`),
  INDEX `idx_rkjm_target_prog` (`program_id`),
  CONSTRAINT `fk_rkjm_target_prog` FOREIGN KEY (`program_id`) REFERENCES `rkjm_programs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rkjm_monitoring` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `program_id` INT NOT NULL,
  `tahun_kalender` YEAR NULL,
  `target_tahun_berjalan` TEXT NULL,
  `realisasi` TEXT NULL,
  `persentase_capaian` DECIMAL(5,2) NULL,
  `penilaian_kualitatif` VARCHAR(50) NULL,
  `status_pelaksanaan` ENUM('belum_dimulai','berjalan','tercapai','tidak_tercapai','ditunda') DEFAULT 'belum_dimulai',
  `kendala` TEXT NULL,
  `tindak_lanjut` TEXT NULL,
  `tanggal_evaluasi` DATE NULL,
  `petugas_evaluasi` VARCHAR(255) NULL,
  `catatan_evaluasi` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_rkjm_mon_prog` (`program_id`),
  INDEX `idx_rkjm_mon_tahun` (`tahun_kalender`),
  CONSTRAINT `fk_rkjm_mon_prog` FOREIGN KEY (`program_id`) REFERENCES `rkjm_programs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rkjm_meta` (
  `meta_key` VARCHAR(64) PRIMARY KEY,
  `meta_value` TEXT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- Bidang pengembangan bawaan (idempoten, tidak menggandakan bila dijalankan ulang).
INSERT INTO `rkjm_fields` (`kode`, `nama`, `urutan`)
SELECT * FROM (
  SELECT 'BID-01' AS kode, 'Pengembangan Kurikulum dan Pembelajaran' AS nama, 1 AS urutan UNION ALL
  SELECT 'BID-02', 'Pengembangan Kompetensi Pendidik dan Tenaga Kependidikan', 2 UNION ALL
  SELECT 'BID-03', 'Pengembangan Peserta Didik', 3 UNION ALL
  SELECT 'BID-04', 'Pengembangan Sarana dan Prasarana', 4 UNION ALL
  SELECT 'BID-05', 'Pengembangan Tata Kelola dan Manajemen Madrasah', 5 UNION ALL
  SELECT 'BID-06', 'Pengembangan Keuangan dan Pembiayaan', 6 UNION ALL
  SELECT 'BID-07', 'Pengembangan Budaya dan Lingkungan Madrasah', 7 UNION ALL
  SELECT 'BID-08', 'Pengembangan Hubungan Masyarakat dan Kemitraan', 8 UNION ALL
  SELECT 'BID-09', 'Pengembangan Mutu, Evaluasi, dan Akreditasi', 9
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM `rkjm_fields` f WHERE f.`kode` = seed.`kode`);

-- Template program bawaan (66 program, 9 bidang) di-seed otomatis oleh
-- aplikasi dari config/rkjm_templates_default.php ketika tabel masih kosong.
