<?php
/**
 * Skema tabel Modul Tabungan Siswa (integrasi e-Tabs ke SIMAD).
 *
 * Data tabungan kini disimpan di database utama SIMAD (db_absensi), bukan
 * di database/aplikasi terpisah e-Tabs.
 *
 * - tb_tabungan          : transaksi setoran & penarikan siswa
 * - tb_tabungan_riwayat  : jejak audit (Aktif / Tidak Aktif) tiap transaksi
 *
 * Key siswa memakai `nisn` (tabel tb_siswa) supaya nyambung dengan modul
 * Pembayaran Siswa & data induk SIMAD.
 */

if (!function_exists('tabungan_ensure_schema')) {
    /**
     * Pastikan tabel tabungan tersedia (self-healing, aman dipanggil berulang).
     */
    function tabungan_ensure_schema(PDO $pdo): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $st = $pdo->query("SHOW TABLES LIKE 'tb_tabungan'");
            $ada = (bool)$st->fetchColumn();

            if (!$ada) {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS `tb_tabungan` (
                        `id_tabungan` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `nisn` VARCHAR(20) NOT NULL,
                        `setor` INT NOT NULL DEFAULT 0,
                        `tarik` INT NOT NULL DEFAULT 0,
                        `tgl` DATE NOT NULL,
                        `jenis` ENUM('ST','TR') NOT NULL,
                        `petugas` VARCHAR(100) NOT NULL DEFAULT '-',
                        `tujuan_tarik` VARCHAR(20) NOT NULL DEFAULT 'lainnya',
                        `jenis_bayar` VARCHAR(150) NULL,
                        `jenis_bayar_id` VARCHAR(100) NULL,
                        `keterangan_tarik` TEXT NULL,
                        `payment_ref` VARCHAR(100) NULL,
                        `payment_sync` VARCHAR(20) NOT NULL DEFAULT 'none',
                        `payment_detail` LONGTEXT NULL,
                        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id_tabungan`),
                        KEY `idx_tabungan_nisn` (`nisn`),
                        KEY `idx_tabungan_tgl` (`tgl`),
                        KEY `idx_tabungan_jenis` (`jenis`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            }

            $st = $pdo->query("SHOW TABLES LIKE 'tb_tabungan_riwayat'");
            $ada = (bool)$st->fetchColumn();

            if (!$ada) {
                $pdo->exec(
                    "CREATE TABLE IF NOT EXISTS `tb_tabungan_riwayat` (
                        `id_riwayat` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `id_tabungan_asali` INT UNSIGNED NOT NULL DEFAULT 0,
                        `nisn` VARCHAR(20) NOT NULL,
                        `setor` INT NOT NULL DEFAULT 0,
                        `tarik` INT NOT NULL DEFAULT 0,
                        `tgl` DATE NOT NULL,
                        `jenis` ENUM('ST','TR') NOT NULL,
                        `petugas` VARCHAR(100) NOT NULL DEFAULT '-',
                        `status` ENUM('Aktif','Tidak Aktif') NOT NULL DEFAULT 'Aktif',
                        `tgl_hapus` DATETIME NULL,
                        `petugas_hapus` VARCHAR(100) NULL,
                        `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id_riwayat`),
                        KEY `idx_tabungan_riwayat_asli` (`id_tabungan_asali`),
                        KEY `idx_tabungan_riwayat_nisn` (`nisn`),
                        KEY `idx_tabungan_riwayat_status` (`status`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                );
            }
        } catch (Throwable $e) {
            error_log('Tabungan: gagal menyiapkan skema tabel - ' . $e->getMessage());
        }
    }
}

if (!function_exists('tabungan_schema_ready')) {
    /**
     * True bila tabel tabungan sudah siap dipakai.
     */
    function tabungan_schema_ready(PDO $pdo): bool
    {
        try {
            $a = $pdo->query("SHOW TABLES LIKE 'tb_tabungan'")->fetchColumn();
            $b = $pdo->query("SHOW TABLES LIKE 'tb_tabungan_riwayat'")->fetchColumn();
            return (bool)$a && (bool)$b;
        } catch (Throwable $e) {
            return false;
        }
    }
}
