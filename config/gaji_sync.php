<?php
/**
 * Sinkronisasi data guru SIMAD (tb_guru) dengan modul gaji (tabel `guru`).
 *
 * Modul gaji menyimpan daftar tenaga pendidik pada tabel `guru`.
 * Supaya data gaji guru/wali di akun SIMAD selalu sama dengan modul gaji,
 * setiap guru di tb_guru harus punya baris yang tertaut lewat `simad_id_guru`.
 *
 * Fungsi ini aman dipanggil berkali-kali (idempoten) dan tidak menghapus data gaji.
 */

if (!function_exists('gaji_normalisasi_nama')) {
    function gaji_normalisasi_nama($nama): string
    {
        $nama = strtolower(trim((string)$nama));
        $nama = preg_replace('/[^a-z0-9]+/', ' ', $nama);
        return trim((string)$nama);
    }
}

if (!function_exists('gaji_jenis_kelamin_modul')) {
    /**
     * tb_guru menyimpan 'Laki-laki'/'Perempuan', modul gaji memakai 'L'/'P'.
     */
    function gaji_jenis_kelamin_modul($jk): string
    {
        $jk = strtolower(trim((string)$jk));
        if ($jk === 'l' || strpos($jk, 'laki') === 0) {
            return 'L';
        }
        if ($jk === 'p' || strpos($jk, 'perem') === 0) {
            return 'P';
        }
        return 'L';
    }
}

if (!function_exists('gaji_modul_guru_taut')) {
    /**
     * Pasang tautan simad_id_guru pada baris modul gaji yang sudah ada.
     */
    function gaji_modul_guru_taut(PDO $pdo, int $id_modul, int $id_guru): bool
    {
        if ($id_modul <= 0 || $id_guru <= 0) {
            return false;
        }
        try {
            $st = $pdo->prepare("UPDATE `guru` SET simad_id_guru = ? WHERE id = ? AND (simad_id_guru IS NULL OR simad_id_guru = 0)");
            $st->execute([$id_guru, $id_modul]);
            return $st->rowCount() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('gaji_modul_guru_buat')) {
    /**
     * Buat baris baru pada tabel `guru` (modul gaji) untuk guru SIMAD.
     */
    function gaji_modul_guru_buat(PDO $pdo, array $teacher): ?array
    {
        $id_guru = (int)($teacher['id_guru'] ?? 0);
        if ($id_guru <= 0) {
            return null;
        }

        $nama = trim((string)($teacher['nama_guru'] ?? ''));
        if ($nama === '') {
            return null;
        }

        $nuptk = trim((string)($teacher['nuptk'] ?? ''));
        $tgl_lahir = trim((string)($teacher['tanggal_lahir'] ?? ''));
        if ($tgl_lahir === '' || $tgl_lahir === '0000-00-00') {
            $tgl_lahir = null;
        }
        $tmt = trim((string)($teacher['tmt'] ?? ''));
        $tmt_tahun = ($tmt !== '' && $tmt !== '0000-00-00') ? (int)substr($tmt, 0, 4) : null;

        try {
            $sql = "INSERT INTO `guru`
                (simad_id_guru, nuptk, nama_lengkap, jenis_kelamin, tmt, tempat_lahir, tanggal_lahir, jabatan, status_pegawai, jumlah_jam_mengajar, foto)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $st = $pdo->prepare($sql);
            $st->execute([
                $id_guru,
                $nuptk !== '' ? $nuptk : null,
                $nama,
                gaji_jenis_kelamin_modul($teacher['jenis_kelamin'] ?? ''),
                $tmt_tahun,
                trim((string)($teacher['tempat_lahir'] ?? '')) !== '' ? trim((string)$teacher['tempat_lahir']) : null,
                $tgl_lahir,
                trim((string)($teacher['jabatan'] ?? '')) !== '' ? trim((string)$teacher['jabatan']) : null,
                'Honorer',
                0,
                'default.jpg',
            ]);

            $id_modul = (int)$pdo->lastInsertId();
            if ($id_modul <= 0) {
                return null;
            }
        } catch (Throwable $e) {
            return null;
        }

        $st = $pdo->prepare("SELECT * FROM `guru` WHERE id = ? LIMIT 1");
        $st->execute([$id_modul]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('gaji_modul_guru_resolve')) {
    /**
     * Ambil baris modul gaji milik guru SIMAD.
     * Urutan: simad_id_guru -> nuptk -> nama. Bila baris ditemukan tanpa tautan,
     * tautan dipasang otomatis. Bila belum ada, baris baru dibuat.
     */
    function gaji_modul_guru_resolve(PDO $pdo, array $teacher): ?array
    {
        $id_guru = (int)($teacher['id_guru'] ?? 0);
        if ($id_guru <= 0) {
            return null;
        }

        $nuptk = trim((string)($teacher['nuptk'] ?? ''));
        $nama = trim((string)($teacher['nama_guru'] ?? ''));

        // 1) Sudah tertaut lewat simad_id_guru
        try {
            $st = $pdo->prepare("SELECT * FROM `guru` WHERE simad_id_guru = ? LIMIT 1");
            $st->execute([$id_guru]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        } catch (Throwable $e) {
            return null;
        }

        // 2) Cocokkan NUPTK
        if ($nuptk !== '') {
            try {
                $st = $pdo->prepare("SELECT * FROM `guru` WHERE nuptk = ? LIMIT 1");
                $st->execute([$nuptk]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    gaji_modul_guru_taut($pdo, (int)$row['id'], $id_guru);
                    $row['simad_id_guru'] = $id_guru;
                    return $row;
                }
            } catch (Throwable $e) {
                // lanjut ke pencocokan nama
            }
        }

        // 3) Cocokkan nama (persis, lalu normalisasi)
        if ($nama !== '') {
            try {
                $st = $pdo->prepare("SELECT * FROM `guru` WHERE nama_lengkap = ? LIMIT 1");
                $st->execute([$nama]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    gaji_modul_guru_taut($pdo, (int)$row['id'], $id_guru);
                    $row['simad_id_guru'] = $id_guru;
                    return $row;
                }
            } catch (Throwable $e) {
                // lanjut
            }

            try {
                $rows = $pdo->query("SELECT id, nama_lengkap FROM `guru`")->fetchAll(PDO::FETCH_ASSOC);
                $target = gaji_normalisasi_nama($nama);
                if ($target !== '') {
                    foreach ($rows as $r) {
                        if (gaji_normalisasi_nama($r['nama_lengkap'] ?? '') === $target) {
                            gaji_modul_guru_taut($pdo, (int)$r['id'], $id_guru);
                            $st = $pdo->prepare("SELECT * FROM `guru` WHERE id = ? LIMIT 1");
                            $st->execute([(int)$r['id']]);
                            $row = $st->fetch(PDO::FETCH_ASSOC);
                            if ($row) {
                                $row['simad_id_guru'] = $id_guru;
                                return $row;
                            }
                        }
                    }
                }
            } catch (Throwable $e) {
                // lanjut membuat baris baru
            }
        }

        // 4) Belum ada di modul gaji -> buat otomatis
        return gaji_modul_guru_buat($pdo, $teacher);
    }
}

if (!function_exists('gaji_ambil_gaji_pokok')) {
    /**
     * Gaji pokok bulanan guru dari tabel gaji_pokok modul gaji.
     * Urutan: baris berjalan (periode kosong) -> baris periode target -> baris periode terakhir.
     * Riwayat per-periode tidak pernah ditimpa.
     */
    function gaji_ambil_gaji_pokok(PDO $pdo, int $guru_id, string $periode = ''): float
    {
        if ($guru_id <= 0) {
            return 0.0;
        }

        try {
            $st = $pdo->prepare("SELECT jumlah FROM gaji_pokok WHERE guru_id = ? AND (periode = '' OR periode IS NULL) ORDER BY id DESC LIMIT 1");
            $st->execute([$guru_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return (float)($row['jumlah'] ?? 0);
            }

            if ($periode !== '') {
                $st = $pdo->prepare("SELECT jumlah FROM gaji_pokok WHERE guru_id = ? AND periode = ? ORDER BY id DESC LIMIT 1");
                $st->execute([$guru_id, $periode]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    return (float)($row['jumlah'] ?? 0);
                }
            }

            $st = $pdo->prepare("SELECT jumlah FROM gaji_pokok WHERE guru_id = ? ORDER BY periode DESC, id DESC LIMIT 1");
            $st->execute([$guru_id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return (float)($row['jumlah'] ?? 0);
            }
        } catch (Throwable $e) {
            return 0.0;
        }

        return 0.0;
    }
}

if (!function_exists('gaji_sync_guru_modul')) {
    /**
     * Sinkronkan seluruh data guru SIMAD ke tabel `guru` modul gaji.
     * - Menaut guru yang belum tertaut (berdasarkan NUPTK / nama).
     * - Membuat baris baru untuk guru yang belum ada di modul gaji.
     * - Menyesuaikan nama/NUPTK/jenis kelamin bila berubah di SIMAD.
     * Data gaji (gaji pokok, tunjangan, potongan, legger) tidak disentuh.
     *
     * @return array{dibuat:int, ditaut:int, diperbarui:int}
     */
    function gaji_sync_guru_modul(PDO $pdo): array
    {
        $stat = ['dibuat' => 0, 'ditaut' => 0, 'diperbarui' => 0];

        try {
            $teachers = $pdo->query("SELECT id_guru, nama_guru, nuptk, jenis_kelamin, tempat_lahir, tanggal_lahir, tmt, jabatan FROM tb_guru ORDER BY id_guru ASC")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $stat;
        }

        foreach ($teachers as $teacher) {
            $id_guru = (int)($teacher['id_guru'] ?? 0);
            if ($id_guru <= 0) {
                continue;
            }

            $sebelum = null;
            try {
                $st = $pdo->prepare("SELECT * FROM `guru` WHERE simad_id_guru = ? LIMIT 1");
                $st->execute([$id_guru]);
                $sebelum = $st->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {
                continue;
            }

            $row = $sebelum ?: gaji_modul_guru_resolve($pdo, $teacher);
            if (!$row) {
                continue;
            }

            if (!$sebelum) {
                $stat['dibuat']++;
                continue;
            }

            // Segarkan identitas dasar bila berubah di SIMAD
            $nama = trim((string)($teacher['nama_guru'] ?? ''));
            $nuptk = trim((string)($teacher['nuptk'] ?? ''));
            $jk = gaji_jenis_kelamin_modul($teacher['jenis_kelamin'] ?? '');

            $set = [];
            $params = [];
            if ($nama !== '' && $nama !== trim((string)($row['nama_lengkap'] ?? ''))) {
                $set[] = 'nama_lengkap = ?';
                $params[] = $nama;
            }
            if ($nuptk !== '' && $nuptk !== trim((string)($row['nuptk'] ?? ''))) {
                $set[] = 'nuptk = ?';
                $params[] = $nuptk;
            }
            if ($jk !== '' && $jk !== trim((string)($row['jenis_kelamin'] ?? ''))) {
                $set[] = 'jenis_kelamin = ?';
                $params[] = $jk;
            }

            if ($set) {
                $params[] = (int)$row['id'];
                try {
                    $st = $pdo->prepare("UPDATE `guru` SET " . implode(', ', $set) . " WHERE id = ?");
                    $st->execute($params);
                    if ($st->rowCount() > 0) {
                        $stat['diperbarui']++;
                    }
                } catch (Throwable $e) {
                    // abaikan
                }
            }
        }

        return $stat;
    }
}
