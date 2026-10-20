<?php
/**
 * Ringkasan pembayaran untuk dashboard bendahara.
 * Memakai koneksi dan helper logika dari modul Pembayaran Siswa agar angka
 * tetap sama dengan halaman /pembayaran/index.php.
 */

require_once __DIR__ . '/../pembayaran/config/config.php';

if (!function_exists('bendahara_pembayaran_summary')) {
    /**
     * @return array{
     *     tahun_ajaran:string,
     *     tahun_ini:int,
     *     jml_siswa:int,
     *     jml_jenis:int,
     *     total_bayar:int,
     *     total_tagihan_aktif:int,
     *     jenis_totals:array
     * }
     */
    function bendahara_pembayaran_summary($koneksi): array
    {
        $tahun_ajaran_aktif = get_tahun_ajaran_aktif($koneksi);
        $tahun_ajaran_aktif_esc = mysqli_real_escape_string($koneksi, (string) $tahun_ajaran_aktif);
        $tahun_ini = (int) date('Y');

        $jml_siswa = 0;
        $q_siswa = mysqli_query($koneksi, "
            SELECT COUNT(*) AS total
            FROM siswa s
            JOIN kelas k ON s.id_kelas = k.id_kelas
            WHERE LOWER(k.nama_kelas) NOT LIKE '%alumni%'
        ");
        if ($q_siswa) {
            $d_siswa = mysqli_fetch_assoc($q_siswa);
            $jml_siswa = (int) ($d_siswa['total'] ?? 0);
        }

        $jml_jenis = 0;
        $q_jenis = mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM jenis_bayar WHERE status = 'Aktif'");
        if ($q_jenis) {
            $d_jenis = mysqli_fetch_assoc($q_jenis);
            $jml_jenis = (int) ($d_jenis['total'] ?? 0);
        }

        $total_bayar = 0;
        $q_bayar = mysqli_query($koneksi, "SELECT SUM(jumlah_bayar) AS total FROM pembayaran WHERE tahun_ajaran = '$tahun_ajaran_aktif_esc'");
        if ($q_bayar) {
            $d_bayar = mysqli_fetch_assoc($q_bayar);
            $total_bayar = (int) ($d_bayar['total'] ?? 0);
        }

        $jenis_totals = [];
        $q_jenis_total = mysqli_query($koneksi, "
            SELECT
                jb.id_jenis_bayar,
                jb.nama_pembayaran,
                COALESCE(SUM(p.jumlah_bayar), 0) AS total_bayar_jenis
            FROM jenis_bayar jb
            LEFT JOIN pembayaran p ON p.id_jenis_bayar = jb.id_jenis_bayar AND p.tahun_ajaran = '$tahun_ajaran_aktif_esc'
            WHERE jb.status = 'Aktif'
            GROUP BY jb.id_jenis_bayar, jb.nama_pembayaran
            ORDER BY
                CASE
                    WHEN LOWER(jb.nama_pembayaran) LIKE '%ekstrakurikuler%' THEN 1
                    WHEN LOWER(jb.nama_pembayaran) LIKE '%lks%' THEN 2
                    WHEN LOWER(jb.nama_pembayaran) LIKE '%ujian%' THEN 3
                    WHEN LOWER(jb.nama_pembayaran) LIKE '%rekreasi%' THEN 4
                    ELSE 5
                END,
                jb.nama_pembayaran ASC
        ");
        if ($q_jenis_total) {
            while ($row = mysqli_fetch_assoc($q_jenis_total)) {
                $jenis_totals[] = $row;
            }
        }

        $total_tagihan_aktif = 0;
        $q_siswa_tagihan = mysqli_query($koneksi, "
            SELECT s.nisn
            FROM siswa s
        ");
        if ($q_siswa_tagihan) {
            while ($siswa_tagihan = mysqli_fetch_assoc($q_siswa_tagihan)) {
                $tagihan_aktif = cek_tagihan_tunggakan($koneksi, $siswa_tagihan['nisn'], $tahun_ajaran_aktif);
                if (!$tagihan_aktif) {
                    continue;
                }
                foreach ($tagihan_aktif as $item) {
                    $total_tagihan_aktif += (int) ($item['sisa'] ?? 0);
                }
            }
        }

        return [
            'tahun_ajaran' => (string) $tahun_ajaran_aktif,
            'tahun_ini' => $tahun_ini,
            'jml_siswa' => $jml_siswa,
            'jml_jenis' => $jml_jenis,
            'total_bayar' => $total_bayar,
            'total_tagihan_aktif' => $total_tagihan_aktif,
            'jenis_totals' => $jenis_totals,
        ];
    }
}
