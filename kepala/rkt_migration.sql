-- ============================================================
-- Migrasi Fitur RKT (Rencana Kerja Tahunan) - SIMAD
-- Aman dijalankan berulang pada database berisi data.
-- Tidak menghapus tabel atau mereset data yang sudah ada.
--
-- Prasyarat: tabel RKJM sudah tersedia (rkjm_documents,
-- rkjm_programs, rkjm_program_targets, rkjm_fields, rkjm_meta).
--
-- Catatan:
-- 1. Aplikasi juga membuat tabel ini otomatis (CREATE TABLE IF NOT EXISTS)
--    saat halaman RKT pertama kali dibuka.
-- 2. Template RKT bawaan (30 program, 9 bidang) di-seed otomatis oleh
--    rktSeedTemplates() saat pertama kali dibuka, dengan penanda
--    rkjm_meta.rkt_templates_seeded. Data tidak di-hardcode di sini.
-- 3. Foreign key ditambahkan dengan pengecekan agar migrasi aman diulang.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `rkt_documents` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `nama_madrasah` VARCHAR(255) NULL,
  `nomor_dokumen` VARCHAR(100) NULL,
  `tahun` YEAR NOT NULL,
  `rkjm_document_id` INT NULL,
  `visi` TEXT NULL,
  `misi` TEXT NULL,
  `tujuan_pengembangan` TEXT NULL,
  `prioritas` TEXT NULL,
  `dasar_penyusunan` TEXT NULL,
  `tanggal_penyusunan` DATE NULL,
  `nama_kepala` VARCHAR(255) NULL,
  `status` ENUM('draf','penyusunan','menunggu','disahkan','diarsipkan') NOT NULL DEFAULT 'draf',
  `catatan` TEXT NULL,
  `created_by` VARCHAR(100) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_rkt_doc_tahun_madrasah` (`tahun`, `nama_madrasah`),
  INDEX `idx_rkt_doc_tahun` (`tahun`),
  INDEX `idx_rkt_doc_status` (`status`),
  INDEX `idx_rkt_doc_rkjm` (`rkjm_document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rkt_template_programs` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `kode` VARCHAR(30) NOT NULL,
  `field_id` INT NULL,
  `bidang_nama` VARCHAR(255) NULL,
  `nama_program` VARCHAR(255) NOT NULL,
  `latar_belakang` TEXT NULL,
  `tujuan` TEXT NULL,
  `sasaran` TEXT NULL,
  `indikator` TEXT NULL,
  `target` TEXT NULL,
  `penanggung_jawab` VARCHAR(255) NULL,
  `estimasi_anggaran` DECIMAL(15,2) DEFAULT 0,
  `sumber_dana` VARCHAR(255) NULL,
  `output` TEXT NULL,
  `monitoring_evaluasi` TEXT NULL,
  `catatan` TEXT NULL,
  `bulan_mulai` TINYINT NULL,
  `bulan_selesai` TINYINT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_rkt_tpl_kode` (`kode`),
  INDEX `idx_rkt_tpl_field` (`field_id`),
  INDEX `idx_rkt_tpl_active` (`is_active`),
  INDEX `idx_rkt_tpl_nama` (`nama_program`(120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rkt_template_activities` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `template_program_id` INT NOT NULL,
  `nama_kegiatan` VARCHAR(255) NOT NULL,
  `tujuan` TEXT NULL,
  `indikator` TEXT NULL,
  `target_volume` VARCHAR(100) NULL,
  `satuan` VARCHAR(50) NULL,
  `bulan_mulai` TINYINT NULL,
  `bulan_selesai` TINYINT NULL,
  `penanggung_jawab` VARCHAR(255) NULL,
  `estimasi_biaya` DECIMAL(15,2) DEFAULT 0,
  `sumber_dana` VARCHAR(255) NULL,
  `output` TEXT NULL,
  `urutan` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_rkt_ta_tpl` (`template_program_id`),
  INDEX `idx_rkt_ta_urutan` (`urutan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rkt_programs` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `document_id` INT NOT NULL,
  `rkjm_program_id` INT NULL,
  `rkjm_document_id` INT NULL,
  `rkjm_referensi` VARCHAR(255) NULL,
  `template_id` INT NULL,
  `field_id` INT NULL,
  `bidang_nama` VARCHAR(255) NULL,
  `kode_program` VARCHAR(30) NULL,
  `nama_program` VARCHAR(255) NOT NULL,
  `kondisi_awal` TEXT NULL,
  `tujuan` TEXT NULL,
  `sasaran` TEXT NULL,
  `indikator` TEXT NULL,
  `target_tahunan` TEXT NULL,
  `jadwal_mulai` DATE NULL,
  `jadwal_selesai` DATE NULL,
  `penanggung_jawab` VARCHAR(255) NULL,
  `estimasi_anggaran` DECIMAL(15,2) DEFAULT 0,
  `sumber_dana` VARCHAR(255) NULL,
  `output` TEXT NULL,
  `monitoring_evaluasi` TEXT NULL,
  `catatan` TEXT NULL,
  `status` ENUM('belum_dimulai','berjalan','selesai','tertunda','tidak_terlaksana') DEFAULT 'belum_dimulai',
  `urutan` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_rkt_prog_doc` (`document_id`),
  INDEX `idx_rkt_prog_rkjm` (`rkjm_program_id`),
  INDEX `idx_rkt_prog_field` (`field_id`),
  INDEX `idx_rkt_prog_status` (`status`),
  INDEX `idx_rkt_prog_pj` (`penanggung_jawab`(120)),
  INDEX `idx_rkt_prog_nama` (`nama_program`(120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rkt_activities` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `program_id` INT NOT NULL,
  `template_activity_id` INT NULL,
  `nama_kegiatan` VARCHAR(255) NOT NULL,
  `tujuan` TEXT NULL,
  `indikator` TEXT NULL,
  `target_volume` VARCHAR(100) NULL,
  `satuan` VARCHAR(50) NULL,
  `jadwal_mulai` DATE NULL,
  `jadwal_selesai` DATE NULL,
  `penanggung_jawab` VARCHAR(255) NULL,
  `estimasi_biaya` DECIMAL(15,2) DEFAULT 0,
  `sumber_dana` VARCHAR(255) NULL,
  `output` TEXT NULL,
  `status` ENUM('belum_dimulai','berjalan','selesai','tertunda','tidak_terlaksana') DEFAULT 'belum_dimulai',
  `urutan` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_rkt_act_prog` (`program_id`),
  INDEX `idx_rkt_act_status` (`status`),
  INDEX `idx_rkt_act_nama` (`nama_kegiatan`(120))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `rkt_monitoring` (
  `id` INT PRIMARY KEY AUTO_INCREMENT,
  `program_id` INT NOT NULL,
  `activity_id` INT NULL,
  `tanggal_pelaksanaan` DATE NULL,
  `target` TEXT NULL,
  `realisasi` TEXT NULL,
  `persentase_capaian` DECIMAL(5,2) NULL,
  `penilaian_kualitatif` VARCHAR(50) NULL,
  `status_pelaksanaan` ENUM('belum_dimulai','berjalan','selesai','tertunda','tidak_terlaksana') DEFAULT 'belum_dimulai',
  `kendala` TEXT NULL,
  `tindak_lanjut` TEXT NULL,
  `output_aktual` TEXT NULL,
  `bukti` TEXT NULL,
  `catatan_evaluasi` TEXT NULL,
  `petugas_evaluasi` VARCHAR(255) NULL,
  `realisasi_biaya` DECIMAL(15,2) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_rkt_mon_prog` (`program_id`),
  INDEX `idx_rkt_mon_act` (`activity_id`),
  INDEX `idx_rkt_mon_status` (`status_pelaksanaan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- Foreign key idempoten (dilewati bila constraint sudah ada).
-- ------------------------------------------------------------
DROP PROCEDURE IF EXISTS `rkt_add_fk`;
DELIMITER $$
CREATE PROCEDURE `rkt_add_fk`(
  IN p_table VARCHAR(64), IN p_fk VARCHAR(64), IN p_column VARCHAR(64),
  IN p_ref_table VARCHAR(64), IN p_ref_column VARCHAR(64), IN p_on_delete VARCHAR(20)
)
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND CONSTRAINT_NAME = p_fk
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
  ) THEN
    SET @rkt_sql = CONCAT(
      'ALTER TABLE `', p_table, '` ADD CONSTRAINT `', p_fk, '` FOREIGN KEY (`', p_column,
      '`) REFERENCES `', p_ref_table, '` (`', p_ref_column,
      '`) ON DELETE ', p_on_delete, ' ON UPDATE CASCADE'
    );
    PREPARE rkt_stmt FROM @rkt_sql;
    EXECUTE rkt_stmt;
    DEALLOCATE PREPARE rkt_stmt;
  END IF;
END$$
DELIMITER ;

CALL rkt_add_fk('rkt_documents', 'fk_rkt_doc_rkjm', 'rkjm_document_id', 'rkjm_documents', 'id', 'SET NULL');
CALL rkt_add_fk('rkt_template_programs', 'fk_rkt_tpl_field', 'field_id', 'rkjm_fields', 'id', 'SET NULL');
CALL rkt_add_fk('rkt_template_activities', 'fk_rkt_ta_tpl', 'template_program_id', 'rkt_template_programs', 'id', 'CASCADE');
CALL rkt_add_fk('rkt_programs', 'fk_rkt_prog_doc', 'document_id', 'rkt_documents', 'id', 'CASCADE');
CALL rkt_add_fk('rkt_programs', 'fk_rkt_prog_rkjm', 'rkjm_program_id', 'rkjm_programs', 'id', 'SET NULL');
CALL rkt_add_fk('rkt_programs', 'fk_rkt_prog_rkjm_doc', 'rkjm_document_id', 'rkjm_documents', 'id', 'SET NULL');
CALL rkt_add_fk('rkt_programs', 'fk_rkt_prog_field', 'field_id', 'rkjm_fields', 'id', 'SET NULL');
CALL rkt_add_fk('rkt_programs', 'fk_rkt_prog_tpl', 'template_id', 'rkt_template_programs', 'id', 'SET NULL');
CALL rkt_add_fk('rkt_activities', 'fk_rkt_act_prog', 'program_id', 'rkt_programs', 'id', 'CASCADE');
CALL rkt_add_fk('rkt_activities', 'fk_rkt_act_ta', 'template_activity_id', 'rkt_template_activities', 'id', 'SET NULL');
CALL rkt_add_fk('rkt_monitoring', 'fk_rkt_mon_prog', 'program_id', 'rkt_programs', 'id', 'CASCADE');
CALL rkt_add_fk('rkt_monitoring', 'fk_rkt_mon_act', 'activity_id', 'rkt_activities', 'id', 'CASCADE');

DROP PROCEDURE IF EXISTS `rkt_add_fk`;
