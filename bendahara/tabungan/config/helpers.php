<?php
/**
 * Helper Modul Tabungan Siswa (integrasi e-Tabs ke SIMAD).
 *
 * Semua query memakai PDO prepared statement. Nama fungsi diawali
 * "tabungan_" agar tidak bentrok dengan modul lain di SIMAD.
 */

if (!function_exists('tabungan_logo_src')) {
    /**
     * Sumber gambar logo madrasah untuk halaman cetak.
     * Memakai URL file (bukan base64) supaya respons HTTP tidak besar & tetap utuh.
     */
    function tabungan_logo_src(array $profile): string
    {
        $logo = trim((string)($profile['logo'] ?? ''));
        if ($logo === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $logo)) {
            return $logo;
        }

        $file = __DIR__ . '/../../../assets/img/' . $logo;
        if (is_file($file) && is_readable($file)) {
            $base = rtrim(app_base_path(), '/');
            return $base . '/assets/img/' . rawurlencode($logo);
        }

        return '';
    }
}

if (!function_exists('tabungan_rupiah')) {
    function tabungan_rupiah($nilai): string
    {
        $n = is_numeric($nilai) ? (float)$nilai : 0;
        return 'Rp ' . number_format($n, 0, ',', '.');
    }
}

if (!function_exists('tabungan_angka')) {
    /** Angka bulat tanpa pemisah ribuan. */
    function tabungan_angka($nilai): string
    {
        $n = is_numeric($nilai) ? (int)$nilai : 0;
        return (string)$n;
    }
}

if (!function_exists('tabungan_tanggal_indo')) {
    function tabungan_tanggal_indo(?string $tgl, bool $dengan_waktu = false): string
    {
        $tgl = trim((string)$tgl);
        if ($tgl === '' || $tgl === '0000-00-00') {
            return '-';
        }
        $ts = strtotime($tgl);
        if ($ts === false) {
            return $tgl;
        }
        return date($dengan_waktu ? 'd M Y H:i' : 'd M Y', $ts);
    }
}

if (!function_exists('tabungan_nama_bulan')) {
    function tabungan_nama_bulan(int $bulan): string
    {
        $nama = [
            1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
            'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
        ];
        return $nama[$bulan] ?? (string)$bulan;
    }
}

if (!function_exists('tabungan_aktor_nama')) {
    /**
     * Nama aktor transaksi tabungan.
     * Hanya Admin & Bendahara yang boleh bertransaksi (gate di config.php).
     */
    function tabungan_aktor_nama(): string
    {
        $level = strtolower(trim((string)($_SESSION['level'] ?? '')));
        if ($level === 'admin') {
            return 'Admin';
        }
        if ($level === 'bendahara') {
            return 'Bendahara';
        }
        $nama = trim((string)($_SESSION['nama'] ?? ''));
        if ($nama === '') {
            $nama = trim((string)($_SESSION['nama_lengkap'] ?? ''));
        }
        if ($nama === '') {
            $nama = trim((string)($_SESSION['username'] ?? ''));
        }
        return $nama !== '' ? $nama : '-';
    }
}

if (!function_exists('tabungan_aktor_label')) {
    /**
     * Tampilkan nilai kolom petugas / petugas_hapus sebagai "Admin" atau "Bendahara".
     * Data lama bernilai "Petugas" ditampilkan "-" karena istilah tersebut dihapus.
     */
    function tabungan_aktor_label($nilai): string
    {
        $v = trim((string)$nilai);
        if ($v === '' || $v === '-') {
            return '-';
        }
        $lv = strtolower($v);
        if (str_contains($lv, 'admin')) {
            return 'Admin';
        }
        if (str_contains($lv, 'bendahara')) {
            return 'Bendahara';
        }
        if ($lv === 'petugas') {
            return '-';
        }
        return $v;
    }
}

if (!function_exists('tabungan_saldo')) {
    /** Saldo tabungan siswa (setor - tarik). */
    function tabungan_saldo(PDO $pdo, string $nisn): int
    {
        if ($nisn === '') {
            return 0;
        }
        $st = $pdo->prepare("SELECT COALESCE(SUM(setor),0) - COALESCE(SUM(tarik),0) AS saldo FROM tb_tabungan WHERE nisn = ?");
        $st->execute([$nisn]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return (int)($row['saldo'] ?? 0);
    }
}

if (!function_exists('tabungan_total')) {
    /**
     * Ringkasan keseluruhan tabungan.
     * @return array{total_setor:int,total_tarik:int,saldo:int,total_transaksi:int,siswa_menabung:int}
     */
    function tabungan_total(PDO $pdo): array
    {
        $out = [
            'total_setor' => 0,
            'total_tarik' => 0,
            'saldo' => 0,
            'total_transaksi' => 0,
            'siswa_menabung' => 0,
        ];
        try {
            $st = $pdo->query(
                "SELECT
                    COALESCE(SUM(CASE WHEN jenis = 'ST' THEN setor ELSE 0 END), 0) AS total_setor,
                    COALESCE(SUM(CASE WHEN jenis = 'TR' THEN tarik ELSE 0 END), 0) AS total_tarik,
                    COUNT(*) AS total_transaksi,
                    COUNT(DISTINCT nisn) AS siswa_menabung
                 FROM tb_tabungan"
            );
            $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
            $out['total_setor'] = (int)($row['total_setor'] ?? 0);
            $out['total_tarik'] = (int)($row['total_tarik'] ?? 0);
            $out['saldo'] = $out['total_setor'] - $out['total_tarik'];
            $out['total_transaksi'] = (int)($row['total_transaksi'] ?? 0);
            $out['siswa_menabung'] = (int)($row['siswa_menabung'] ?? 0);
        } catch (Throwable $e) {
            error_log('Tabungan: gagal menghitung total - ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('tabungan_statistik_bulanan')) {
    /**
     * Statistik jumlah transaksi per bulan (1..12) untuk grafik.
     * @return array<int, array{jumlah:int,total:int}>
     */
    function tabungan_statistik_bulanan(PDO $pdo, int $tahun, string $jenis): array
    {
        $data = [];
        for ($i = 1; $i <= 12; $i++) {
            $data[$i] = ['jumlah' => 0, 'total' => 0];
        }
        try {
            $st = $pdo->prepare(
                "SELECT MONTH(tgl) AS bln, COUNT(*) AS jml, COALESCE(SUM(CASE WHEN jenis = 'ST' THEN setor ELSE tarik END), 0) AS total
                 FROM tb_tabungan
                 WHERE jenis = ? AND YEAR(tgl) = ?
                 GROUP BY MONTH(tgl)"
            );
            $st->execute([$jenis, $tahun]);
            while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $bln = (int)($row['bln'] ?? 0);
                if ($bln >= 1 && $bln <= 12) {
                    $data[$bln] = ['jumlah' => (int)($row['jml'] ?? 0), 'total' => (int)($row['total'] ?? 0)];
                }
            }
        } catch (Throwable $e) {
            error_log('Tabungan: gagal menghitung statistik bulanan - ' . $e->getMessage());
        }
        return $data;
    }
}

if (!function_exists('tabungan_siswa')) {
    /** Data siswa (induk) berdasarkan NISN. */
    function tabungan_siswa(PDO $pdo, string $nisn): ?array
    {
        if ($nisn === '') {
            return null;
        }
        $st = $pdo->prepare(
            "SELECT s.nisn, s.nama_siswa, s.id_kelas, k.nama_kelas
             FROM tb_siswa s
             LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
             WHERE s.nisn = ?
             LIMIT 1"
        );
        $st->execute([$nisn]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ? $row : null;
    }
}

if (!function_exists('tabungan_siswa_aktif')) {
    /** Jumlah siswa aktif (kelas bukan Alumni). */
    function tabungan_siswa_aktif(PDO $pdo): int
    {
        try {
            $st = $pdo->query(
                "SELECT COUNT(*)
                 FROM tb_siswa s
                 LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
                 WHERE s.id_kelas IS NOT NULL AND (k.nama_kelas IS NULL OR k.nama_kelas <> 'Alumni')"
            );
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('tabungan_kelas')) {
    /** Daftar kelas untuk filter. */
    function tabungan_kelas(PDO $pdo): array
    {
        try {
            $st = $pdo->query("SELECT id_kelas, nama_kelas FROM tb_kelas ORDER BY nama_kelas ASC");
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('tabungan_daftar_siswa')) {
    /**
     * Daftar siswa + saldo tabungan.
     *
     * @param array{q?:string,id_kelas?:string,hanya_aktif?:bool,punya_transaksi?:bool,limit?:int} $opt
     */
    function tabungan_daftar_siswa(PDO $pdo, array $opt = []): array
    {
        $sql = "SELECT s.nisn, s.nama_siswa, s.id_kelas, k.nama_kelas,
                       COALESCE(t.total_setor, 0) AS total_setor,
                       COALESCE(t.total_tarik, 0) AS total_tarik,
                       COALESCE(t.total_setor, 0) - COALESCE(t.total_tarik, 0) AS saldo
                FROM tb_siswa s
                LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas
                LEFT JOIN (
                    SELECT nisn, SUM(setor) AS total_setor, SUM(tarik) AS total_tarik
                    FROM tb_tabungan GROUP BY nisn
                ) t ON t.nisn = s.nisn";
        $where = [];
        $params = [];

        if (!empty($opt['q'])) {
            $where[] = '(s.nama_siswa LIKE ? OR s.nisn LIKE ?)';
            $cari = '%' . trim((string)$opt['q']) . '%';
            $params[] = $cari;
            $params[] = $cari;
        }
        if (!empty($opt['id_kelas'])) {
            $where[] = 's.id_kelas = ?';
            $params[] = (int)$opt['id_kelas'];
        }
        if (!empty($opt['hanya_aktif'])) {
            $where[] = "(s.id_kelas IS NOT NULL AND (k.nama_kelas IS NULL OR k.nama_kelas <> 'Alumni'))";
        }
        if (!empty($opt['punya_transaksi'])) {
            $where[] = 't.nisn IS NOT NULL';
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY k.nama_kelas ASC, s.nama_siswa ASC';
        if (!empty($opt['limit'])) {
            $sql .= ' LIMIT ' . (int)$opt['limit'];
        }

        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Tabungan: gagal mengambil daftar siswa - ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('tabungan_transaksi')) {
    /**
     * Daftar transaksi tabungan (opsional filter nisn / jenis / periode).
     */
    function tabungan_transaksi(PDO $pdo, array $opt = []): array
    {
        $sql = "SELECT t.*, s.nama_siswa, k.nama_kelas
                FROM tb_tabungan t
                LEFT JOIN tb_siswa s ON s.nisn = t.nisn
                LEFT JOIN tb_kelas k ON k.id_kelas = s.id_kelas";
        $where = [];
        $params = [];

        if (!empty($opt['nisn'])) {
            $where[] = 't.nisn = ?';
            $params[] = (string)$opt['nisn'];
        }
        if (!empty($opt['jenis'])) {
            $where[] = 't.jenis = ?';
            $params[] = (string)$opt['jenis'];
        }
        if (!empty($opt['dari'])) {
            $where[] = 't.tgl >= ?';
            $params[] = (string)$opt['dari'];
        }
        if (!empty($opt['sampai'])) {
            $where[] = 't.tgl <= ?';
            $params[] = (string)$opt['sampai'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY t.tgl DESC, t.id_tabungan DESC';
        if (!empty($opt['limit'])) {
            $sql .= ' LIMIT ' . (int)$opt['limit'];
        }

        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Tabungan: gagal mengambil transaksi - ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('tabungan_riwayat')) {
    /**
     * Riwayat (audit) transaksi tabungan.
     */
    function tabungan_riwayat(PDO $pdo, array $opt = []): array
    {
        $sql = "SELECT r.*, s.nama_siswa
                FROM tb_tabungan_riwayat r
                LEFT JOIN tb_siswa s ON s.nisn = r.nisn";
        $where = [];
        $params = [];

        if (!empty($opt['status'])) {
            $where[] = 'r.status = ?';
            $params[] = (string)$opt['status'];
        }
        if (!empty($opt['jenis'])) {
            $where[] = 'r.jenis = ?';
            $params[] = (string)$opt['jenis'];
        }
        if (!empty($opt['nisn'])) {
            $where[] = 'r.nisn = ?';
            $params[] = (string)$opt['nisn'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY r.tgl DESC, r.id_riwayat DESC';
        if (!empty($opt['limit'])) {
            $sql .= ' LIMIT ' . (int)$opt['limit'];
        }

        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('Tabungan: gagal mengambil riwayat - ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('tabungan_tambah_transaksi')) {
    /**
     * Simpan transaksi baru (setoran / penarikan) + catat ke riwayat.
     *
     * @return array{ok:bool,id:int,message:string}
     */
    function tabungan_tambah_transaksi(PDO $pdo, array $data): array
    {
        $nisn = trim((string)($data['nisn'] ?? ''));
        $jenis = strtoupper(trim((string)($data['jenis'] ?? 'ST')));
        $setor = (int)($data['setor'] ?? 0);
        $tarik = (int)($data['tarik'] ?? 0);
        $tgl = trim((string)($data['tgl'] ?? date('Y-m-d')));
        $petugas = trim((string)($data['petugas'] ?? tabungan_aktor_nama()));

        if ($nisn === '' || $jenis === '' || $tgl === '') {
            return ['ok' => false, 'id' => 0, 'message' => 'Data transaksi belum lengkap.'];
        }

        $tujuan = $jenis === 'TR' ? ((string)($data['tujuan_tarik'] ?? 'lainnya') === 'pembayaran' ? 'pembayaran' : 'lainnya') : 'lainnya';

        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare(
                "INSERT INTO tb_tabungan
                    (nisn, setor, tarik, tgl, jenis, petugas, tujuan_tarik, jenis_bayar, jenis_bayar_id,
                     keterangan_tarik, payment_ref, payment_sync, payment_detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $st->execute([
                $nisn,
                $setor,
                $tarik,
                $tgl,
                $jenis,
                $petugas,
                $tujuan,
                $data['jenis_bayar'] ?? null,
                $data['jenis_bayar_id'] ?? null,
                $data['keterangan_tarik'] ?? null,
                $data['payment_ref'] ?? null,
                (string)($data['payment_sync'] ?? 'none'),
                $data['payment_detail'] ?? null,
            ]);
            $id = (int)$pdo->lastInsertId();

            $st = $pdo->prepare(
                "INSERT INTO tb_tabungan_riwayat
                    (id_tabungan_asali, nisn, setor, tarik, tgl, jenis, petugas, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktif')"
            );
            $st->execute([$id, $nisn, $setor, $tarik, $tgl, $jenis, $petugas]);

            $pdo->commit();

            return ['ok' => true, 'id' => $id, 'message' => 'Transaksi berhasil disimpan.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Tabungan: gagal menyimpan transaksi - ' . $e->getMessage());
            return ['ok' => false, 'id' => 0, 'message' => 'Gagal menyimpan transaksi: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('tabungan_ubah_transaksi')) {
    /**
     * Ubah transaksi (tanpa menyentuh riwayat; riwayat lama tetap 'Aktif'
     * sebagai jejak audit dan dibuatkan baris riwayat baru).
     */
    function tabungan_ubah_transaksi(PDO $pdo, int $id, array $data): array
    {
        $st = $pdo->prepare("SELECT * FROM tb_tabungan WHERE id_tabungan = ? LIMIT 1");
        $st->execute([$id]);
        $lama = $st->fetch(PDO::FETCH_ASSOC);
        if (!$lama) {
            return ['ok' => false, 'message' => 'Transaksi tidak ditemukan.'];
        }

        $jenis = (string)$lama['jenis'];
        $setor = (int)($data['setor'] ?? 0);
        $tarik = (int)($data['tarik'] ?? 0);
        $tgl = trim((string)($data['tgl'] ?? $lama['tgl']));
        $petugas = tabungan_aktor_nama();

        try {
            $pdo->beginTransaction();

            $up = $pdo->prepare(
                "UPDATE tb_tabungan
                 SET nisn = ?, setor = ?, tarik = ?, tgl = ?, petugas = ?, tujuan_tarik = ?,
                     jenis_bayar = ?, jenis_bayar_id = ?, keterangan_tarik = ?
                 WHERE id_tabungan = ?"
            );
            $up->execute([
                trim((string)($data['nisn'] ?? $lama['nisn'])),
                $setor,
                $tarik,
                $tgl,
                $petugas,
                (string)($data['tujuan_tarik'] ?? $lama['tujuan_tarik']),
                $data['jenis_bayar'] ?? $lama['jenis_bayar'],
                $data['jenis_bayar_id'] ?? $lama['jenis_bayar_id'],
                $data['keterangan_tarik'] ?? $lama['keterangan_tarik'],
                $id,
            ]);

            $up = $pdo->prepare(
                "UPDATE tb_tabungan_riwayat
                 SET nisn = ?, setor = ?, tarik = ?, tgl = ?, petugas = ?, status = 'Tidak Aktif', tgl_hapus = NOW(), petugas_hapus = ?
                 WHERE id_tabungan_asali = ? AND status = 'Aktif'"
            );
            $up->execute([
                trim((string)($data['nisn'] ?? $lama['nisn'])),
                $setor,
                $tarik,
                $tgl,
                $petugas,
                $petugas,
                $id,
            ]);

            $ins = $pdo->prepare(
                "INSERT INTO tb_tabungan_riwayat
                    (id_tabungan_asali, nisn, setor, tarik, tgl, jenis, petugas, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'Aktif')"
            );
            $ins->execute([
                $id,
                trim((string)($data['nisn'] ?? $lama['nisn'])),
                $setor,
                $tarik,
                $tgl,
                $jenis,
                $petugas,
            ]);

            $pdo->commit();

            return ['ok' => true, 'message' => 'Transaksi berhasil diubah.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Tabungan: gagal mengubah transaksi - ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Gagal mengubah transaksi: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('tabungan_hapus_transaksi')) {
    /**
     * Hapus transaksi (dengan tetap menyimpan jejak audit di riwayat).
     */
    function tabungan_hapus_transaksi(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare("SELECT * FROM tb_tabungan WHERE id_tabungan = ? LIMIT 1");
        $st->execute([$id]);
        $trx = $st->fetch(PDO::FETCH_ASSOC);
        if (!$trx) {
            return ['ok' => false, 'message' => 'Transaksi tidak ditemukan.'];
        }

        $petugas = tabungan_aktor_nama();

        try {
            $pdo->beginTransaction();

            $del = $pdo->prepare("DELETE FROM tb_tabungan WHERE id_tabungan = ?");
            $del->execute([$id]);

            $up = $pdo->prepare(
                "UPDATE tb_tabungan_riwayat
                 SET status = 'Tidak Aktif', tgl_hapus = NOW(), petugas_hapus = ?
                 WHERE id_tabungan_asali = ? AND status = 'Aktif'"
            );
            $up->execute([$petugas, $id]);

            $pdo->commit();

            return ['ok' => true, 'message' => 'Transaksi berhasil dihapus.', 'trx' => $trx];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Tabungan: gagal menghapus transaksi - ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Gagal menghapus transaksi: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('tabungan_hapus_riwayat')) {
    /**
     * Hapus baris riwayat berstatus 'Tidak Aktif'.
     */
    function tabungan_hapus_riwayat(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
        if (!$ids) {
            return ['ok' => false, 'message' => 'Tidak ada riwayat yang dipilih.'];
        }

        $place = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = $pdo->prepare("SELECT id_riwayat, status FROM tb_tabungan_riwayat WHERE id_riwayat IN ($place)");
            $st->execute($ids);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            $boleh = [];
            foreach ($rows as $row) {
                if ((string)($row['status'] ?? '') !== 'Aktif') {
                    $boleh[] = (int)$row['id_riwayat'];
                }
            }
            if (!$boleh) {
                return ['ok' => false, 'message' => 'Riwayat aktif tidak dapat dihapus.'];
            }

            $place2 = implode(',', array_fill(0, count($boleh), '?'));
            $del = $pdo->prepare("DELETE FROM tb_tabungan_riwayat WHERE id_riwayat IN ($place2)");
            $del->execute($boleh);

            return ['ok' => true, 'message' => 'Berhasil menghapus ' . count($boleh) . ' riwayat transaksi.', 'jumlah' => count($boleh)];
        } catch (Throwable $e) {
            error_log('Tabungan: gagal menghapus riwayat - ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Gagal menghapus riwayat: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('tabungan_aktivitas')) {
    /** Aktivitas terbaru (log aktivitas SIMAD). */
    function tabungan_aktivitas(PDO $pdo, int $limit = 8): array
    {
        try {
            $st = $pdo->prepare("SELECT username, action, description, created_at FROM tb_activity_log ORDER BY created_at DESC, id DESC LIMIT ?");
            $st->execute([max(1, $limit)]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('tabungan_reset_semua')) {
    /**
     * Maintenance: kosongkan tabel tabungan (transaksi + riwayat).
     *
     * @return array{ok:bool,message:string}
     */
    function tabungan_reset_semua(PDO $pdo): array
    {
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM tb_tabungan_riwayat");
            $pdo->exec("DELETE FROM tb_tabungan");
            $pdo->exec("ALTER TABLE tb_tabungan AUTO_INCREMENT = 1");
            $pdo->exec("ALTER TABLE tb_tabungan_riwayat AUTO_INCREMENT = 1");
            $pdo->commit();
            return ['ok' => true, 'message' => 'Seluruh data tabungan siswa berhasil dihapus.'];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Tabungan: gagal reset data - ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Gagal menghapus data tabungan: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('tabungan_hapus_riwayat_tidak_aktif')) {
    /** Maintenance: hapus riwayat berstatus 'Tidak Aktif'. */
    function tabungan_hapus_riwayat_tidak_aktif(PDO $pdo): array
    {
        try {
            $st = $pdo->prepare("DELETE FROM tb_tabungan_riwayat WHERE status <> 'Aktif'");
            $st->execute();
            return ['ok' => true, 'message' => $st->rowCount() . ' riwayat transaksi tidak aktif berhasil dihapus.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Gagal menghapus riwayat: ' . $e->getMessage()];
        }
    }
}
