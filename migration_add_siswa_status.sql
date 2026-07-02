-- Add status column to tb_siswa to track active/inactive/alumni
ALTER TABLE `tb_siswa` 
ADD COLUMN `status` ENUM('aktif', 'non-aktif', 'alumni') NOT NULL DEFAULT 'aktif' AFTER `password`;