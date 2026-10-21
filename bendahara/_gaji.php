<?php
/**
 * Helper data penggajian untuk level bendahara.
 * Sumber data: tabel gaji di database SIMAD (hasil integrasi SIGaji).
 */

if (!function_exists('bendahara_fetch_gaji')) {
    /**
     * @return array{status:string,guru:array,period_info:array,message:string,cached:bool,stale:bool}
     */
    function bendahara_fetch_gaji(bool $force = false): array
    {
        global $pdo;

        $empty = [
            'status' => 'error',
            'guru' => [],
            'period_info' => [],
            'message' => 'Data penggajian tidak tersedia.',
            'cached' => false,
            'stale' => false,
        ];

        try {
            $settings = [];
            $q = $pdo->query("SELECT * FROM settings WHERE id = 1 LIMIT 1");
            if ($q) {
                $settings = $q->fetch(PDO::FETCH_ASSOC) ?: [];
            }

            $periode_aktif = trim((string)($settings['periode_aktif'] ?? '')) ?: date('Y-m');
            $jumlah_periode = max(1, (int)($settings['jumlah_periode'] ?? 1));
            $periode_mulai = trim((string)($settings['periode_mulai'] ?? ''));
            $periode_akhir = trim((string)($settings['periode_akhir'] ?? ''));

            $periods = [];
            if ($jumlah_periode > 1 && $periode_mulai !== '' && $periode_akhir !== '') {
                $start = new DateTime($periode_mulai . '-01');
                $end = new DateTime($periode_akhir . '-01');
                while ($start <= $end) {
                    $periods[] = $start->format('Y-m');
                    $start->modify('+1 month');
                }
            }
            if (!$periods) {
                $periods[] = $periode_aktif;
            }

            $period_info = [
                'periode_aktif' => $periode_aktif,
                'target_periode' => $periode_aktif,
                'jumlah_periode' => $jumlah_periode,
                'periode_mulai' => $jumlah_periode > 1 ? $periode_mulai : '',
                'periode_akhir' => $jumlah_periode > 1 ? $periode_akhir : '',
            ];

            $placeholders = implode(',', array_fill(0, count($periods), '?'));

            $sql = "SELECT lg.*, g.simad_id_guru, g.nama_lengkap, g.nip
                    FROM legger_gaji lg
                    JOIN guru g ON g.id = lg.guru_id
                    WHERE lg.periode IN ($placeholders)
                    ORDER BY g.nama_lengkap ASC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($periods);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fallback: belum ada legger pada periode aktif -> hitung dari data master.
            if (!$rows) {
                $rows = [];
                $guruRows = $pdo->query("SELECT * FROM guru ORDER BY nama_lengkap ASC")->fetchAll(PDO::FETCH_ASSOC);
                $periode = $periods[0];
                $tjStmt = $pdo->prepare("SELECT t.nama_tunjangan, td.jumlah FROM tunjangan_detail td JOIN tunjangan t ON t.id = td.tunjangan_id WHERE td.guru_id = ? AND td.periode = ? AND t.aktif = 1");
                $ptStmt = $pdo->prepare("SELECT p.nama_potongan, pd.jumlah FROM potongan_detail pd JOIN potongan p ON p.id = pd.potongan_id WHERE pd.guru_id = ? AND pd.periode = ? AND p.aktif = 1");
                $gpStmt = $pdo->prepare("SELECT jumlah FROM gaji_pokok WHERE guru_id = ? LIMIT 1");
                foreach ($guruRows as $g) {
                    $tjStmt->execute([(int)$g['id'], $periode]);
                    $tunjangan = $tjStmt->fetchAll(PDO::FETCH_ASSOC);
                    $ptStmt->execute([(int)$g['id'], $periode]);
                    $potongan = $ptStmt->fetchAll(PDO::FETCH_ASSOC);
                    $gpStmt->execute([(int)$g['id']]);
                    $pokok = (float)($gpStmt->fetchColumn() ?: 0);

                    $totalTunj = 0.0;
                    foreach ($tunjangan as $t) { $totalTunj += (float)($t['jumlah'] ?? 0); }
                    $totalPot = 0.0;
                    foreach ($potongan as $p) { $totalPot += (float)($p['jumlah'] ?? 0); }

                    $rows[] = [
                        'guru_id' => (int)$g['id'],
                        'simad_id_guru' => (int)($g['simad_id_guru'] ?? 0),
                        'nama_lengkap' => (string)($g['nama_lengkap'] ?? ''),
                        'nip' => (string)($g['nip'] ?? ''),
                        'gaji_pokok' => $pokok,
                        'total_tunjangan' => $totalTunj,
                        'total_potongan' => $totalPot,
                        'gaji_bersih' => $pokok + $totalTunj - $totalPot,
                        'tunjangan' => array_map(static fn($t) => ['nama_tunjangan' => (string)($t['nama_tunjangan'] ?? ''), 'jumlah_bulanan' => (float)($t['jumlah'] ?? 0)], $tunjangan),
                        'potongan' => array_map(static fn($p) => ['nama_potongan' => (string)($p['nama_potongan'] ?? ''), 'jumlah_bulanan' => (float)($p['jumlah'] ?? 0)], $potongan),
                    ];
                }
            } else {
                $detailStmt = $pdo->prepare("SELECT jenis, nama_item, jumlah FROM legger_detail WHERE legger_id = ? ORDER BY id ASC");
                foreach ($rows as &$row) {
                    $detailStmt->execute([(int)$row['id']]);
                    $details = $detailStmt->fetchAll(PDO::FETCH_ASSOC);
                    $tunjangan = [];
                    $potongan = [];
                    foreach ($details as $d) {
                        $item = ['nama' => (string)($d['nama_item'] ?? ''), 'nominal' => (float)($d['jumlah'] ?? 0)];
                        if (($d['jenis'] ?? '') === 'tunjangan') {
                            $tunjangan[] = ['nama_tunjangan' => $item['nama'], 'jumlah_bulanan' => $item['nominal']];
                        } elseif (($d['jenis'] ?? '') === 'potongan') {
                            $potongan[] = ['nama_potongan' => $item['nama'], 'jumlah_bulanan' => $item['nominal']];
                        }
                    }
                    $row['tunjangan'] = $tunjangan;
                    $row['potongan'] = $potongan;
                }
                unset($row);
            }

            return [
                'status' => 'success',
                'guru' => $rows,
                'period_info' => $period_info,
                'message' => '',
                'cached' => false,
                'stale' => false,
            ];
        } catch (Throwable $e) {
            $empty['message'] = 'Gagal memuat data penggajian: ' . $e->getMessage();
            return $empty;
        }
    }
}

if (!function_exists('bendahara_periode_label')) {
    function bendahara_periode_label(string $periode): string
    {
        $bulan = [
            '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
            '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
            '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember',
        ];
        if (preg_match('/^(\d{4})-(\d{2})$/', $periode, $m)) {
            return ($bulan[$m[2]] ?? $m[2]) . ' ' . $m[1];
        }
        return $periode;
    }
}

if (!function_exists('bendahara_gaji_map_guru')) {
    /**
     * Petakan simad_id_guru ke data guru lokal (fallback tampilan).
     * @return array<int,array{id_guru:int,nama_guru:string,nip:string}>
     */
    function bendahara_gaji_map_guru(PDO $pdo): array
    {
        $map = [];
        try {
            foreach ($pdo->query("SELECT id_guru, nama_guru, nuptk, kode_guru FROM tb_guru") as $g) {
                $map[(int)$g['id_guru']] = [
                    'id_guru' => (int)$g['id_guru'],
                    'nama_guru' => (string)($g['nama_guru'] ?? ''),
                    'nip' => (string)(trim((string)($g['nuptk'] ?? '')) !== '' ? $g['nuptk'] : ($g['kode_guru'] ?? '')),
                ];
            }
        } catch (Throwable $e) {
            // biarkan kosong
        }
        return $map;
    }
}
