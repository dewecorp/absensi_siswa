-- Add password_plain column to tb_guru table
ALTER TABLE `tb_guru` ADD COLUMN `password_plain` VARCHAR(255) NULL AFTER `password`;
