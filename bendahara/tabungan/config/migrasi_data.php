<?php
/**
 * Migrasi data e-Tabs (db_tabsis) -> tabel tabungan SIMAD (db_absensi).
 *
 * Dipakai sekali saat integrasi modul tabungan. Idempoten: data yang sudah
 * ada (id & nisn sama) tidak diduplikasi.
 *
 * Pemetaan:
 * - e-Tabs `tb_tabungan`      -> SIMAD `tb_tabungan`
 * - e-Tabs `tb_riwayat`      -> SIMAD `tb_tabungan_riwayat`
 * - e-Tabs `tb_siswa.nis`    -> SIMAD `tb_siswa.nisn` (dicek, data tanpa siswa dilewati)
 */

if (!function_exists('tabungan_rekonsiliasi_pembayaran_legacy')) {
    /**
     * Rekonsiliasi penarikan tabungan lama (e-Tabs) dengan tabel `pembayaran`.
     *
     * e-Tabs menyimpan payment_ref miliknya sendiri (ETAB-...), sedangkan baris
     * pembayarannya memakai no_transaksi modul Pembayaran (mis. ETAB-202609-001).
     * Fungsi ini menautkan keduanya & menyelaraskan format payment_detail ke
     * format modul Tabungan yang baru.
     *
     * @return array{ok:bool,message:string,taut:int}
     */
    function tabungan_rekonsiliasi_pembayaran_legacy(PDO $pdo): array
    {
        $hasil = ['ok' => false, 'message' => '', 'taut' => 0];
        $koneksi = tabungan_pembayaran_koneksi();
        if (!$koneksi) {
            $hasil['message'] = 'Koneksi database Pembayaran tidak tersedia.';
            return $hasil;
        }

        $st = $pdo->query(
            "SELECT id_tabungan, nisn, tarik, payment_ref, payment_detail
             FROM tb_tabungan
             WHERE tujuan_tarik = 'pembayaran' AND payment_sync = 'success'
               AND (payment_ref IS NULL OR payment_ref = '' OR payment_ref NOT LIKE 'TAB-%')"
        );
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) {
            $hasil['ok'] = true;
            $hasil['message'] = 'Tidak ada penarikan lama yang perlu direkonsiliasi.';
            return $hasil;
        }

        $terpakai = [];
        $upd = $pdo->prepare("UPDATE tb_tabungan SET payment_ref = ?, payment_detail = ? WHERE id_tabungan = ?");

        foreach ($rows as $r) {
            $detail = json_decode((string)($r['payment_detail'] ?? ''), true);
            if (!is_array($detail) || empty($detail['items'])) {
                continue;
            }

            // Cari baris pembayaran yang cocok (belum dipakai withdrawal lain).
            $sql = "SELECT id_pembayaran, no_transaksi, bulan_bayar, id_jenis_bayar, jumlah_bayar
                    FROM pembayaran
                    WHERE nisn = ? AND jumlah_bayar = ? AND no_transaksi NOT LIKE 'TAB-%'";
            $stmt = mysqli_prepare($koneksi, $sql);
            if (!$stmt) {
                continue;
            }
            $nisn = (string)$r['nisn'];
            $jumlah = (int)$r['tarik'];
            mysqli_stmt_bind_param($stmt, 'si', $nisn, $jumlah);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);

            $cocok = null;
            while ($res && $baris = mysqli_fetch_assoc($res)) {
                $no = (string)($baris['no_transaksi'] ?? '');
                if ($no === '' || isset($terpakai[$no])) {
                    continue;
                }
                $cocok = $baris;
                break;
            }
            mysqli_stmt_close($stmt);

            if (!$cocok) {
                continue;
            }

            // Samakan format detail item ke format modul Tabungan baru.
            $items_baru = [];
            foreach ((array)$detail['items'] as $item) {
                $bulan = [];
                foreach ((array)($item['rincian'] ?? []) as $rincian) {
                    $b = trim((string)($rincian['bulan'] ?? ''));
                    if ($b !== '') {
                        $bulan[] = $b;
                    }
                }
                if (!$bulan && !empty($item['bulan'])) {
                    $bulan = array_values((array)$item['bulan']);
                }

                $items_baru[] = [
                    'id_jenis_bayar' => (int)($item['id'] ?? $item['id_jenis_bayar'] ?? 0),
                    'nama_pembayaran' => (string)($item['nama'] ?? $item['nama_pembayaran'] ?? ''),
                    'tipe_bayar' => ((string)($item['tipe'] ?? $item['tipe_bayar'] ?? 'bulanan')) === 'cicilan' ? 'Cicilan' : 'Bulanan',
                    'nominal' => (int)($item['nominal_per_bulan'] ?? $item['nominal'] ?? 0),
                    'sisa' => (int)($item['sisa'] ?? 0),
                    'bulan' => $bulan,
                    'no_transaksi' => (string)$cocok['no_transaksi'],
                ];
            }

            $detail_baru = [
                'items' => $items_baru,
                'tahun_ajaran' => (string)($detail['items'][0]['tahun_ajaran'] ?? ''),
            ];

            $upd->execute([
                (string)$cocok['no_transaksi'],
                json_encode($detail_baru, JSON_UNESCAPED_UNICODE),
                (int)$r['id_tabungan'],
            ]);

            $terpakai[(string)$cocok['no_transaksi']] = true;
            $hasil['taut']++;
        }

        $hasil['ok'] = true;
        $hasil['message'] = 'Rekonsiliasi penarikan lama: ' . $hasil['taut'] . ' transaksi ditautkan ke data Pembayaran.';
        return $hasil;
    }
}

if (!function_exists('tabungan_migrasi_dari_etabs')) {
    /**
     * @return array{ok:bool,message:string,tabungan:int,riwayat:int,lewati:int,detail:array}
     */
    function tabungan_migrasi_dari_etabs(PDO $pdo, string $db_etabs = 'db_tabsis'): array
    {
        $hasil = [
            'ok' => false,
            'message' => '',
            'tabungan' => 0,
            'riwayat' => 0,
            'lewati' => 0,
            'detail' => [],
        ];

        // Sumber data e-Tabs
        try {
            $src = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . $db_etabs . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (Throwable $e) {
            $hasil['message'] = 'Database sumber e-Tabs (' . $db_etabs . ') tidak dapat diakses: ' . $e->getMessage();
            return $hasil;
        }

        // Daftar NISN yang valid (data siswa SIMAD)
        $st = $pdo->query("SELECT nisn FROM tb_siswa");
        $siswa_valid = array_flip(array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN)));

        try {
            $pdo->beginTransaction();

            /* ---------- Transaksi ---------- */
            $trx = $src->query("SELECT id_tabungan, nis, setor, tarik, tgl, jenis, petugas,
                                       tujuan_tarik, jenis_bayar, jenis_bayar_id, keterangan_tarik,
                                       payment_ref, payment_sync, payment_detail
                                FROM tb_tabungan ORDER BY id_tabungan")->fetchAll(PDO::FETCH_ASSOC);
            $ins = $pdo->prepare(
                "INSERT INTO tb_tabungan
                    (id_tabungan, nisn, setor, tarik, tgl, jenis, petugas, tujuan_tarik, jenis_bayar,
                     jenis_bayar_id, keterangan_tarik, payment_ref, payment_sync, payment_detail)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    nisn = VALUES(nisn), setor = VALUES(setor), tarik = VALUES(tarik), tgl = VALUES(tgl),
                    jenis = VALUES(jenis), petugas = VALUES(petugas), tujuan_tarik = VALUES(tujuan_tarik),
                    jenis_bayar = VALUES(jenis_bayar), jenis_bayar_id = VALUES(jenis_bayar_id),
                    keterangan_tarik = VALUES(keterangan_tarik), payment_ref = VALUES(payment_ref),
                    payment_sync = VALUES(payment_sync), payment_detail = VALUES(payment_detail)"
            );

            foreach ($trx as $t) {
                $nis = trim((string)($t['nis'] ?? ''));
                if ($nis === '' || !isset($siswa_valid[$nis])) {
                    $hasil['lewati']++;
                    $hasil['detail'][] = 'Transaksi #' . $t['id_tabungan'] . ' dilewati (siswa ' . $nis . ' tidak ada di SIMAD).';
                    continue;
                }
                $ins->execute([
                    (int)$t['id_tabungan'],
                    $nis,
                    (int)$t['setor'],
                    (int)$t['tarik'],
                    (string)$t['tgl'],
                    (string)$t['jenis'],
                    (string)($t['petugas'] ?? '-'),
                    (string)($t['tujuan_tarik'] ?? 'lainnya'),
                    $t['jenis_bayar'] ?? null,
                    $t['jenis_bayar_id'] ?? null,
                    $t['keterangan_tarik'] ?? null,
                    $t['payment_ref'] ?? null,
                    (string)($t['payment_sync'] ?? 'none'),
                    $t['payment_detail'] ?? null,
                ]);
                $hasil['tabungan']++;
            }

            /* ---------- Riwayat ---------- */
            $riwayat = $src->query("SELECT id_riwayat, id_tabungan_asli, nis, setor, tarik, tgl, jenis, petugas, status, tgl_hapus, petugas_hapus FROM tb_riwayat ORDER BY id_riwayat")->fetchAll(PDO::FETCH_ASSOC);
            $insR = $pdo->prepare(
                "INSERT INTO tb_tabungan_riwayat
                    (id_riwayat, id_tabungan_asali, nisn, setor, tarik, tgl, jenis, petugas, status, tgl_hapus, petugas_hapus)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    id_tabungan_asali = VALUES(id_tabungan_asali), nisn = VALUES(nisn), setor = VALUES(setor),
                    tarik = VALUES(tarik), tgl = VALUES(tgl), jenis = VALUES(jenis), petugas = VALUES(petugas),
                    status = VALUES(status), tgl_hapus = VALUES(tgl_hapus), petugas_hapus = VALUES(petugas_hapus)"
            );

            foreach ($riwayat as $r) {
                $nis = trim((string)($r['nis'] ?? ''));
                if ($nis === '' || !isset($siswa_valid[$nis])) {
                    $hasil['lewati']++;
                    continue;
                }
                $insR->execute([
                    (int)$r['id_riwayat'],
                    (int)$r['id_tabungan_asli'],
                    $nis,
                    (int)$r['setor'],
                    (int)$r['tarik'],
                    (string)$r['tgl'],
                    (string)$r['jenis'],
                    (string)($r['petugas'] ?? '-'),
                    (string)($r['status'] ?? 'Aktif'),
                    $r['tgl_hapus'] ?? null,
                    $r['petugas_hapus'] ?? null,
                ]);
                $hasil['riwayat']++;
            }

            $pdo->commit();
            $hasil['ok'] = true;
            $hasil['message'] = 'Migrasi selesai: ' . $hasil['tabungan'] . ' transaksi & ' . $hasil['riwayat'] . ' riwayat dari e-Tabs'
                . ($hasil['lewati'] > 0 ? ', ' . $hasil['lewati'] . ' baris dilewati (siswa tidak ditemukan di SIMAD)' : '') . '.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $hasil['message'] = 'Migrasi gagal: ' . $e->getMessage();
        }

        return $hasil;
    }
}
